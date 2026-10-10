<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventType;
use App\Enums\TransitionOutcome;
use App\Enums\WebhookProcessingStatus;
use App\Models\Institution;
use App\Models\Invoice;
use App\Models\PaymentTransaction;
use App\Models\WebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentWebhookProcessor
{
    /**
     * Fields stripped from redacted audit payloads.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'webhook_secret',
        'secret',
        'card_number',
        'card_cvv',
        'signature',
    ];

    public function __construct(
        private readonly WebhookSignatureVerifier $verifier = new WebhookSignatureVerifier,
        private readonly InvoiceStateMachine $stateMachine = new InvoiceStateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function process(string $rawBody, ?string $signature, array $payload): ProcessedWebhook
    {
        $receipt = WebhookReceipt::create([
            'event_id' => $this->stringOrNull($payload['event_id'] ?? null),
            'event_type' => $this->stringOrNull($payload['event_type'] ?? null),
            'invoice_number' => $this->stringOrNull($payload['invoice_number'] ?? null),
            'processing_status' => WebhookProcessingStatus::Received,
            'payload_redacted' => $this->redact($payload),
        ]);

        $institutionCode = $this->stringOrNull($payload['institution_code'] ?? null);
        if ($institutionCode === null) {
            return $this->reject($receipt, 'institution_code_missing', 400);
        }

        $institution = Institution::where('code', $institutionCode)->first();
        if (! $institution) {
            return $this->reject($receipt, 'institution_unknown', 401, ['institution_id' => null]);
        }

        $receipt->institution()->associate($institution);

        $eventType = PaymentEventType::tryFrom((string) ($payload['event_type'] ?? ''));
        if (! $eventType) {
            return $this->reject($receipt, 'event_type_unknown', 422);
        }

        $eventId = $this->stringOrNull($payload['event_id'] ?? null);
        if ($eventId === null) {
            return $this->reject($receipt, 'event_id_missing', 422);
        }

        // Idempotency: same (institution, event_id) already seen.
        $duplicate = WebhookReceipt::where('institution_id', $institution->id)
            ->where('event_id', $eventId)
            ->where('id', '<>', $receipt->id)
            ->whereIn('processing_status', [
                WebhookProcessingStatus::Processed,
                WebhookProcessingStatus::Duplicate,
                WebhookProcessingStatus::Ignored,
            ])
            ->exists();

        $signatureValid = $this->verifier->verify($rawBody, $institution->webhook_secret, $signature);
        $receipt->signature_valid = $signatureValid;
        $receipt->save();

        if (! $signatureValid) {
            return $this->reject($receipt, 'signature_invalid', 401);
        }

        if ($duplicate) {
            return $this->finish($receipt, WebhookProcessingStatus::Duplicate, 'event_duplicate', 200);
        }

        $invoiceNumber = $this->stringOrNull($payload['invoice_number'] ?? null);
        if ($invoiceNumber === null) {
            return $this->reject($receipt, 'invoice_number_missing', 422);
        }

        $gatewayTransactionId = $this->stringOrNull($payload['gateway_transaction_id'] ?? null);
        if ($gatewayTransactionId === null) {
            return $this->reject($receipt, 'gateway_transaction_missing', 422);
        }

        $amount = $payload['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            return $this->reject($receipt, 'amount_invalid', 422);
        }
        $amount = number_format((float) $amount, 2, '.', '');

        $occurredAt = $this->parseDateTime($payload['occurred_at'] ?? null);

        return DB::transaction(function () use (
            $receipt, $institution, $eventType, $eventId,
            $invoiceNumber, $gatewayTransactionId, $amount, $occurredAt
        ) {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::forInstitution($institution)
                ->where('invoice_number', $invoiceNumber)
                ->lockForUpdate()
                ->first();

            if (! $invoice) {
                return $this->reject($receipt, 'invoice_not_found', 422);
            }

            // Amount must match exactly for payment.success.
            if ($eventType === PaymentEventType::Success
                && number_format((float) $invoice->amount, 2, '.', '') !== $amount) {
                return $this->reject($receipt, 'amount_mismatch', 422);
            }

            $outcome = $this->stateMachine->decide($invoice->status, $eventType);

            $existingTransaction = PaymentTransaction::where('institution_id', $institution->id)
                ->where('gateway_transaction_id', $gatewayTransactionId)
                ->first();

            if ($existingTransaction) {
                return $this->finish($receipt, WebhookProcessingStatus::Duplicate, 'event_duplicate', 200);
            }

            if ($outcome === TransitionOutcome::MarkPaid || $outcome === TransitionOutcome::MarkExpired) {
                $invoice->status = $outcome === TransitionOutcome::MarkPaid
                    ? InvoiceStatus::Paid
                    : InvoiceStatus::Expired;
                $invoice->save();

                // Only payment.success creates a financial transaction row.
                // payment.expired only moves state; it remains fully audited in webhook_receipts.
                if ($outcome === TransitionOutcome::MarkPaid) {
                    try {
                        $transaction = new PaymentTransaction([
                            'gateway_transaction_id' => $gatewayTransactionId,
                            'event_type' => $eventType,
                            'amount' => $amount,
                            'occurred_at' => $occurredAt,
                        ]);
                        $transaction->institution()->associate($institution);
                        $transaction->invoice()->associate($invoice);
                        $transaction->save();
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (! $this->isUniqueViolation($e)) {
                            throw $e;
                        }

                        // Lost a concurrent insert race for the same gateway
                        // transaction: the unique key held, so treat this
                        // delivery as an idempotent duplicate (HTTP 200).
                        return $this->finish($receipt, WebhookProcessingStatus::Duplicate, 'event_duplicate', 200);
                    }
                }

                return $this->finish($receipt, WebhookProcessingStatus::Processed, null, 200);
            }

            if ($outcome === TransitionOutcome::ConflictAlreadyPaid) {
                return $this->finish($receipt, WebhookProcessingStatus::Ignored, 'success_after_expiry', 200);
            }

            return $this->finish($receipt, WebhookProcessingStatus::Ignored, 'already_final', 200);
        });
    }

    private function finish(
        WebhookReceipt $receipt,
        WebhookProcessingStatus $status,
        ?string $failureReason,
        int $httpStatus
    ): ProcessedWebhook {
        $receipt->processing_status = $status;
        $receipt->failure_reason = $failureReason;
        $receipt->processed_at = now();
        $receipt->save();

        return new ProcessedWebhook($receipt, $httpStatus);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reject(WebhookReceipt $receipt, string $reason, int $httpStatus, array $extra = []): ProcessedWebhook
    {
        if (array_key_exists('institution_id', $extra)) {
            $receipt->institution_id = $extra['institution_id'];
        }
        $receipt->signature_valid ??= false;
        $receipt->processing_status = WebhookProcessingStatus::Rejected;
        $receipt->failure_reason = $reason;
        $receipt->processed_at = now();
        $receipt->save();

        return new ProcessedWebhook($receipt, $httpStatus);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 128, '');
    }

    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || ($e->errorInfo[0] ?? null) === '23000';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }
            $redacted[$key] = is_string($value) ? Str::limit($value, 256, '') : $value;
        }

        return $redacted;
    }

    private function parseDateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
