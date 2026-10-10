<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\WebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
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

    private function createInvoice(string $number = 'INV-W1', string $amount = '1500000.00'): Invoice
    {
        return Invoice::create([
            'institution_id' => $this->alpha->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => $number,
            'amount' => $amount,
            'expires_at' => now()->addDays(30),
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
            'event_id' => 'evt-001',
            'event_type' => 'payment.success',
            'invoice_number' => 'INV-W1',
            'gateway_transaction_id' => 'gw-001',
            'amount' => '1500000.00',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_malformed_json_is_rejected_without_leaking_secrets(): void
    {
        $response = $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{not-json');

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'MALFORMED_PAYLOAD');
        $this->assertStringNotContainsString($this->secret, $response->getContent());
    }

    public function test_invalid_signature_is_rejected_and_audited(): void
    {
        $this->createInvoice();

        $response = $this->postWebhook($this->successPayload(), 'bad-signature');

        $response->assertStatus(401);
        $response->assertJsonPath('data.processing_status', 'rejected');
        $response->assertJsonPath('data.failure_reason', 'signature_invalid');

        $receipt = WebhookReceipt::where('event_id', 'evt-001')->firstOrFail();
        $this->assertFalse((bool) $receipt->signature_valid);
        $this->assertEquals('rejected', $receipt->processing_status->value);
        $this->assertNotNull($receipt->processed_at);

        // Invoice untouched, no money movement.
        $this->assertEquals('unpaid', $this->alpha->invoices()->firstOrFail()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->createInvoice();

        $rawBody = json_encode($this->successPayload(), JSON_UNESCAPED_SLASHES);
        $response = $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $rawBody);

        $response->assertStatus(401);
        $response->assertJsonPath('data.failure_reason', 'signature_invalid');
    }

    public function test_valid_success_marks_paid_and_creates_transaction(): void
    {
        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload());

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'processed');

        $this->assertEquals('paid', $invoice->fresh()->status->value);

        $tx = PaymentTransaction::where('gateway_transaction_id', 'gw-001')->firstOrFail();
        $this->assertEquals($this->alpha->id, $tx->institution_id);
        $this->assertEquals($invoice->id, $tx->invoice_id);
        $this->assertEquals('1500000.00', number_format((float) $tx->amount, 2, '.', ''));

        $receipt = WebhookReceipt::where('event_id', 'evt-001')->firstOrFail();
        $this->assertTrue((bool) $receipt->signature_valid);
        $this->assertEquals('processed', $receipt->processing_status->value);
    }

    public function test_replayed_event_id_is_idempotent_duplicate(): void
    {
        $this->createInvoice();

        $this->postWebhook($this->successPayload())->assertStatus(200);
        $second = $this->postWebhook($this->successPayload());

        $second->assertStatus(200);
        $second->assertJsonPath('data.processing_status', 'duplicate');
        $second->assertJsonPath('data.failure_reason', 'event_duplicate');

        $this->assertEquals(1, PaymentTransaction::where('gateway_transaction_id', 'gw-001')->count());
        $this->assertEquals(2, WebhookReceipt::where('event_id', 'evt-001')->count());
    }

    public function test_reused_gateway_transaction_id_with_new_event_is_duplicate(): void
    {
        $this->createInvoice();

        $this->postWebhook($this->successPayload())->assertStatus(200);

        // New event_id, same gateway transaction: financial row must not double.
        $second = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-002',
        ]));

        $second->assertStatus(200);
        $second->assertJsonPath('data.processing_status', 'duplicate');
        $this->assertEquals(1, PaymentTransaction::count());
    }

    public function test_expired_event_marks_unpaid_invoice_expired(): void
    {
        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-exp-1',
            'event_type' => 'payment.expired',
            'gateway_transaction_id' => 'gw-exp-1',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'processed');
        $this->assertEquals('expired', $invoice->fresh()->status->value);

        // Expiry moves state only; no financial row.
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_late_success_after_expiry_is_ignored_and_stays_expired(): void
    {
        $invoice = $this->createInvoice();

        $this->postWebhook($this->successPayload([
            'event_id' => 'evt-exp-1',
            'event_type' => 'payment.expired',
            'gateway_transaction_id' => 'gw-exp-1',
        ]))->assertStatus(200);

        $late = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-late-1',
            'gateway_transaction_id' => 'gw-late-1',
        ]));

        $late->assertStatus(200);
        $late->assertJsonPath('data.processing_status', 'ignored');
        $late->assertJsonPath('data.failure_reason', 'success_after_expiry');

        $this->assertEquals('expired', $invoice->fresh()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_expired_after_paid_is_ignored_without_moving_backwards(): void
    {
        $invoice = $this->createInvoice();

        $this->postWebhook($this->successPayload())->assertStatus(200);
        $this->assertEquals('paid', $invoice->fresh()->status->value);

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-exp-after-paid',
            'event_type' => 'payment.expired',
            'gateway_transaction_id' => 'gw-exp-2',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'ignored');
        $response->assertJsonPath('data.failure_reason', 'already_final');
        $this->assertEquals('paid', $invoice->fresh()->status->value);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $this->createInvoice();

        $response = $this->postWebhook($this->successPayload(['amount' => '1.00']));

        $response->assertStatus(422);
        $response->assertJsonPath('data.failure_reason', 'amount_mismatch');
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_unparseable_occurred_at_is_rejected(): void
    {
        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload([
            'event_id' => 'evt-bad-ts',
            'gateway_transaction_id' => 'gw-bad-ts',
            'occurred_at' => 'asal-teks-bukan-tanggal',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('data.processing_status', 'rejected');
        $response->assertJsonPath('data.failure_reason', 'occurred_at_invalid');

        // No state movement, no money movement.
        $this->assertEquals('unpaid', $invoice->fresh()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());

        $receipt = WebhookReceipt::where('event_id', 'evt-bad-ts')->firstOrFail();
        $this->assertEquals('rejected', $receipt->processing_status->value);
        $this->assertEquals('occurred_at_invalid', $receipt->failure_reason);
    }

    public function test_missing_occurred_at_stays_nullable(): void
    {
        $invoice = $this->createInvoice();

        $payload = $this->successPayload([
            'event_id' => 'evt-no-ts',
            'gateway_transaction_id' => 'gw-no-ts',
        ]);
        unset($payload['occurred_at']);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'processed');
        $this->assertEquals('paid', $invoice->fresh()->status->value);

        $tx = PaymentTransaction::where('gateway_transaction_id', 'gw-no-ts')->firstOrFail();
        $this->assertNull($tx->occurred_at);
    }

    public function test_unknown_institution_is_rejected(): void
    {
        $response = $this->postWebhook($this->successPayload([
            'institution_code' => 'NOPE',
            'event_id' => 'evt-unknown-inst',
        ]));

        $response->assertStatus(401);
        $response->assertJsonPath('data.failure_reason', 'institution_unknown');
    }

    public function test_audit_payload_is_redacted(): void
    {
        $this->createInvoice();

        $payload = $this->successPayload([
            'event_id' => 'evt-redact-1',
            'gateway_transaction_id' => 'gw-redact-1',
            'webhook_secret' => 'should-never-persist',
            'card_number' => '4111111111111111',
            'signature' => 'should-never-persist',
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $receipt = WebhookReceipt::where('event_id', 'evt-redact-1')->firstOrFail();
        $redacted = $receipt->payload_redacted;

        $this->assertEquals('[REDACTED]', $redacted['webhook_secret']);
        $this->assertEquals('[REDACTED]', $redacted['card_number']);
        $this->assertEquals('[REDACTED]', $redacted['signature']);
        $this->assertStringNotContainsString('should-never-persist', json_encode($redacted));
        $this->assertStringNotContainsString('4111111111111111', json_encode($redacted));
    }

    public function test_webhook_is_tenant_scoped_by_institution_code(): void
    {
        $beta = Institution::create([
            'name' => 'Kampus Beta',
            'code' => 'CAMPUS-BETA',
            'webhook_secret' => 'test-secret-beta',
        ]);

        // Same invoice_number in two tenants.
        $alphaInvoice = $this->createInvoice('INV-SHARED');
        $betaInvoice = Invoice::create([
            'institution_id' => $beta->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => 'INV-SHARED',
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30),
            'status' => InvoiceStatus::Unpaid,
        ]);

        $this->postWebhook($this->successPayload([
            'event_id' => 'evt-tenant-1',
            'gateway_transaction_id' => 'gw-tenant-1',
            'invoice_number' => 'INV-SHARED',
        ]))->assertStatus(200);

        $this->assertEquals('paid', $alphaInvoice->fresh()->status->value);
        $this->assertEquals('unpaid', $betaInvoice->fresh()->status->value);
    }
}
