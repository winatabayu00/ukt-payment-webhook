<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventType;
use App\Enums\TransitionOutcome;
use App\Jobs\NotifyPaymentSuccessJob;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\WebhookReceipt;
use App\Services\InvoiceStateMachine;
use App\Services\PaymentWebhookProcessor;
use App\Services\WebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookQueueMonitoringTest extends TestCase
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

    private function createInvoice(string $number = 'INV-Q1', string $amount = '1500000.00'): Invoice
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
            'event_id' => 'evt-q-001',
            'event_type' => 'payment.success',
            'invoice_number' => 'INV-Q1',
            'gateway_transaction_id' => 'gw-q-001',
            'amount' => '1500000.00',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    public function test_success_dispatches_notification_job(): void
    {
        Queue::fake();

        $invoice = $this->createInvoice();

        $response = $this->postWebhook($this->successPayload());

        $response->assertStatus(200);
        $response->assertJsonPath('data.processing_status', 'processed');

        $transaction = PaymentTransaction::where('gateway_transaction_id', 'gw-q-001')->firstOrFail();
        $receipt = WebhookReceipt::where('event_id', 'evt-q-001')->firstOrFail();

        Queue::assertPushed(NotifyPaymentSuccessJob::class, function (NotifyPaymentSuccessJob $job) use ($invoice, $transaction, $receipt) {
            return $job->institutionId === $this->alpha->id
                && $job->invoiceId === $invoice->id
                && $job->transactionId === $transaction->id
                && $job->receiptId === $receipt->id
                && $job->eventId === 'evt-q-001';
        });
    }

    public function test_expired_event_dispatches_no_job(): void
    {
        Queue::fake();

        $this->createInvoice();

        $this->postWebhook($this->successPayload([
            'event_id' => 'evt-q-exp-1',
            'event_type' => 'payment.expired',
            'gateway_transaction_id' => 'gw-q-exp-1',
        ]))->assertStatus(200);

        Queue::assertNotPushed(NotifyPaymentSuccessJob::class);
    }

    public function test_unexpected_exception_marks_failed_and_returns_500_without_leak(): void
    {
        $this->createInvoice();

        $throwingMachine = new class extends InvoiceStateMachine
        {
            public function decide(InvoiceStatus $current, PaymentEventType $event): TransitionOutcome
            {
                throw new \RuntimeException('boom-internal-detail');
            }
        };

        $this->app->instance(
            PaymentWebhookProcessor::class,
            new PaymentWebhookProcessor(new WebhookSignatureVerifier, $throwingMachine)
        );

        $response = $this->postWebhook($this->successPayload(['event_id' => 'evt-q-fail-1']));

        $response->assertStatus(500);
        $response->assertJsonPath('data.processing_status', 'failed');
        $response->assertJsonPath('data.failure_reason', 'internal_error');
        $this->assertStringNotContainsString('boom-internal-detail', $response->getContent());

        $receipt = WebhookReceipt::where('event_id', 'evt-q-fail-1')->firstOrFail();
        $this->assertEquals('failed', $receipt->processing_status->value);
        $this->assertEquals('internal_error', $receipt->failure_reason);
        $this->assertNotNull($receipt->processed_at);

        // Money never moved.
        $this->assertEquals('unpaid', $this->alpha->invoices()->firstOrFail()->status->value);
        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_notification_job_is_safe_to_rerun_and_skips_missing_records(): void
    {
        // Missing records: must not throw (queue worker stays healthy).
        $job = new NotifyPaymentSuccessJob(999999, 999999, 999999, 999999, 'evt-missing');
        $job->handle();

        $this->assertEquals(0, PaymentTransaction::count());
    }

    public function test_monitor_reports_summary_failures_and_queue(): void
    {
        $this->createInvoice();

        $this->postWebhook($this->successPayload())->assertStatus(200);
        $this->postWebhook($this->successPayload([
            'event_id' => 'evt-q-badsig',
            'gateway_transaction_id' => 'gw-q-badsig',
        ]), 'bad-signature')->assertStatus(401);

        $this->artisan('webhook:monitor')
            ->assertSuccessful()
            ->expectsOutputToContain('processed')
            ->expectsOutputToContain('signature_invalid')
            ->expectsOutputToContain('jobs pending');
    }

    public function test_monitor_scopes_to_institution_and_rejects_unknown(): void
    {
        $this->createInvoice();
        $this->postWebhook($this->successPayload())->assertStatus(200);

        $this->artisan('webhook:monitor', ['--institution' => 'CAMPUS-ALPHA'])
            ->assertSuccessful()
            ->expectsOutputToContain('CAMPUS-ALPHA');

        $this->artisan('webhook:monitor', ['--institution' => 'NOPE'])
            ->assertFailed();
    }
}
