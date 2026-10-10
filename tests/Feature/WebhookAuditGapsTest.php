<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\WebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookAuditGapsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $alpha;

    private string $secret = 'test-secret-alpha';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Institution::create([
            'name' => 'Kampus Alpha',
            'code' => 'CAMPUS-ALPHA',
            'webhook_secret' => $this->secret,
        ]);
    }

    private function createInvoice(string $number = 'INV-G1', string $amount = '1500000.00', mixed $expiresAt = null): Invoice
    {
        return Invoice::create([
            'institution_id' => $this->alpha->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => $number,
            'amount' => $amount,
            'expires_at' => $expiresAt ?? now()->addDays(30),
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(array $payload, ?string $signature = null, bool $sign = true): \Illuminate\Testing\TestResponse
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($sign && $signature === null) {
            $signature = hash_hmac('sha256', $rawBody, $this->secret);
        }

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/payments', [], [], [], $server, $rawBody);
    }

    private function successPayload(array $overrides = []): array
    {
        return array_merge([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_id' => 'evt-gap-001',
            'event_type' => 'payment.success',
            'invoice_number' => 'INV-G1',
            'gateway_transaction_id' => 'gw-gap-001',
            'amount' => '1500000.00',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_nested_sensitive_keys_are_redacted_recursively(): void
    {
        $this->createInvoice();

        $payload = $this->successPayload([
            'event_id' => 'evt-gap-redact',
            'gateway_transaction_id' => 'gw-gap-redact',
            'data' => [
                'card_number' => '4111111111111111',
                'nested' => ['secret' => 'shh', 'ok' => 'keep-me'],
            ],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $receipt = WebhookReceipt::where('event_id', 'evt-gap-redact')->firstOrFail();
        $redacted = $receipt->payload_redacted;

        $this->assertEquals('[REDACTED]', $redacted['data']['card_number']);
        $this->assertEquals('[REDACTED]', $redacted['data']['nested']['secret']);
        $this->assertEquals('keep-me', $redacted['data']['nested']['ok']);
        $this->assertStringNotContainsString('4111111111111111', json_encode($redacted));
    }

    public function test_sha256_prefixed_signature_is_accepted(): void
    {
        $this->createInvoice();

        $payload = $this->successPayload([
            'event_id' => 'evt-gap-prefix',
            'gateway_transaction_id' => 'gw-gap-prefix',
        ]);
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $prefixed = 'sha256='.hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postWebhook($payload, $prefixed, false);

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'processed');
    }

    public function test_stale_occurred_at_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-gap-old',
            'gateway_transaction_id' => 'gw-gap-old',
            'occurred_at' => now()->subDays(31)->toIso8601String(),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('data.failure_reason', 'occurred_at_too_old');
        $this->assertEquals('unpaid', $invoice->fresh()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_future_occurred_at_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-gap-future',
            'gateway_transaction_id' => 'gw-gap-future',
            'occurred_at' => now()->addMinutes(16)->toIso8601String(),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('data.failure_reason', 'occurred_at_in_future');
        $this->assertEquals('unpaid', $invoice->fresh()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_success_for_time_expired_invoice_is_ignored_without_money_movement(): void
    {
        $invoice = $this->createInvoice('INV-G1', '1500000.00', now()->subDay());

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-gap-time-exp',
            'gateway_transaction_id' => 'gw-gap-time-exp',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'ignored');
        $response->assertJsonPath('data.failure_reason', 'success_after_expiry');

        $this->assertEquals('expired', $invoice->fresh()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_webhook_prune_deletes_old_terminal_receipts_but_keeps_failed(): void
    {
        $this->createInvoice();
        $this->postWebhook($this->successPayload())->assertStatus(200);

        $receipt = WebhookReceipt::where('event_id', 'evt-gap-001')->firstOrFail();
        $receipt->created_at = now()->subDays(100);
        $receipt->save();

        $failed = WebhookReceipt::create([
            'event_id' => 'evt-gap-failed',
            'processing_status' => \App\Enums\WebhookProcessingStatus::Failed,
            'failure_reason' => 'internal_error',
        ]);
        $failed->created_at = now()->subDays(100);
        $failed->save();

        $this->artisan('webhook:prune', ['--days' => 90])->assertSuccessful();

        $this->assertNull(WebhookReceipt::find($receipt->id));
        $this->assertNotNull(WebhookReceipt::find($failed->id));

        $this->artisan('webhook:prune', ['--days' => 90, '--include-failed' => true])->assertSuccessful();
        $this->assertNull(WebhookReceipt::find($failed->id));
    }

    public function test_webhook_secret_rotation_command_updates_secret(): void
    {
        $this->artisan('institution:webhook-secret', [
            'code' => 'CAMPUS-ALPHA',
            '--rotate' => true,
            '--secret' => 'rotated-secret-123',
        ])->assertSuccessful();

        $this->assertEquals('rotated-secret-123', $this->alpha->fresh()->webhook_secret);

        // Old secret no longer verifies; new one does.
        $payload = $this->successPayload([
            'event_id' => 'evt-gap-rot',
            'gateway_transaction_id' => 'gw-gap-rot',
            'invoice_number' => 'INV-MISSING-ROT',
        ]);
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $oldSig = hash_hmac('sha256', $rawBody, $this->secret);
        $this->postWebhook($payload, $oldSig, false)->assertStatus(401);
    }
}
