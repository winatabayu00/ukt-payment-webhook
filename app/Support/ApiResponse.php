<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Single standard envelope for every /api/* response.
 *
 * Success (CRUD):
 *   {success:true, message:"...", data:{...|[...]}, meta?:{...}}
 *
 * Error (CRUD + malformed webhook + auth + exceptions):
 *   {success:false, message:"...", error:{code:"...", details?:...}}
 *
 * Webhook deliveries (audited):
 *   {success:bool, message:"...", data:{processing_status, failure_reason}}
 *   processed/duplicate/ignored ⇒ success:true (retry-safe 200)
 *   rejected/failed/malformed   ⇒ success:false (4xx/500)
 *
 * The `data.*` / `error.code` keys are kept stable so existing gateway
 * clients and tests keep passing; `success` + `message` are additive.
 */
class ApiResponse
{
    /**
     * @param  mixed  $data
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * @param  mixed  $details  Laravel-style {field:[messages]} or any JSON-able payload.
     */
    public static function error(string $code, string $message, int $status, mixed $details = null): JsonResponse
    {
        $error = ['code' => $code];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => $error,
        ], $status);
    }

    /**
     * Webhook envelope: keeps audited data.* keys, adds success + message.
     *
     * @param  array<string, mixed>  $data  ['processing_status'=>..., 'failure_reason'=>...]
     */
    public static function webhook(array $data, int $status, bool $success, string $message): JsonResponse
    {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Map a webhook processing_status (+failure_reason) to [success, message].
     *
     * @return array{0: bool, 1: string}
     */
    public static function webhookOutcome(string $processingStatus, ?string $failureReason): array
    {
        if (in_array($processingStatus, ['processed', 'duplicate', 'ignored'], true)) {
            return [true, match ($processingStatus) {
                'duplicate' => 'Duplicate event ignored.',
                'ignored' => $failureReason === 'success_after_expiry'
                    ? 'Late payment ignored; invoice already expired.'
                    : 'Event ignored; invoice already final.',
                default => 'Webhook processed.',
            }];
        }

        return [false, self::webhookFailureMessage($failureReason)];
    }

    public static function webhookFailureMessage(?string $failureReason): string
    {
        return match ($failureReason) {
            'institution_code_missing' => 'Institution code is missing.',
            'institution_unknown' => 'Unknown institution code.',
            'event_type_unknown' => 'Unknown event type.',
            'event_id_missing' => 'Event id is missing.',
            'invoice_number_missing' => 'Invoice number is missing.',
            'gateway_transaction_missing' => 'Gateway transaction id is missing.',
            'amount_invalid' => 'Amount is invalid.',
            'amount_mismatch' => 'Amount does not match the invoice.',
            'invoice_not_found' => 'Invoice not found.',
            'signature_invalid' => 'Invalid webhook signature.',
            'occurred_at_invalid' => 'Occurred_at timestamp is invalid.',
            'occurred_at_too_old' => 'Occurred_at timestamp is too old.',
            'occurred_at_in_future' => 'Occurred_at timestamp is in the future.',
            'event_duplicate' => 'Duplicate event ignored.',
            'already_final', 'success_after_expiry' => 'Event ignored; invoice already final.',
            'internal_error' => 'Internal error; safe to retry.',
            default => 'Webhook rejected.',
        };
    }
}
