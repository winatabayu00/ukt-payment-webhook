<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\PaymentTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Queued side effect for payment.success.
 *
 * Dispatched only after the webhook DB transaction commits (invoice Paid +
 * payment_transactions row persisted). Intentionally side-effect free for now
 * besides structured logging: a safe placeholder where a real notifier
 * (email, campus SIS callback) can be plugged in without touching the
 * synchronous idempotent webhook path.
 *
 * Idempotent: re-running for an already-paid invoice is a no-op success.
 */
class NotifyPaymentSuccessJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var array<int> */
    public $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $institutionId,
        public readonly int $invoiceId,
        public readonly int $transactionId,
        public readonly int $receiptId,
        public readonly ?string $eventId = null,
    ) {}

    public function handle(): void
    {
        $invoice = Invoice::where('institution_id', $this->institutionId)->find($this->invoiceId);
        $transaction = PaymentTransaction::where('institution_id', $this->institutionId)->find($this->transactionId);

        if (! $invoice || ! $transaction) {
            Log::warning('payment.notification.skipped', [
                'institution_id' => $this->institutionId,
                'invoice_id' => $this->invoiceId,
                'transaction_id' => $this->transactionId,
                'receipt_id' => $this->receiptId,
                'event_id' => $this->eventId,
                'reason' => 'record_missing',
            ]);

            return;
        }

        if (! $invoice->status || $invoice->status->value !== 'paid') {
            Log::warning('payment.notification.skipped', [
                'institution_id' => $this->institutionId,
                'invoice_id' => $this->invoiceId,
                'invoice_number' => $invoice->invoice_number,
                'receipt_id' => $this->receiptId,
                'event_id' => $this->eventId,
                'reason' => 'invoice_not_paid',
            ]);

            return;
        }

        // Placeholder for the real side effect (email / SIS callback).
        // Structured, secret-free: ids + gateway reference only.
        Log::info('payment.notification.sent', [
            'institution_id' => $this->institutionId,
            'invoice_id' => $this->invoiceId,
            'invoice_number' => $invoice->invoice_number,
            'transaction_id' => $this->transactionId,
            'gateway_transaction_id' => $transaction->gateway_transaction_id,
            'receipt_id' => $this->receiptId,
            'event_id' => $this->eventId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(): array
    {
        return [
            'payment-notification',
            'institution:'.$this->institutionId,
            'invoice:'.$this->invoiceId,
        ];
    }
}
