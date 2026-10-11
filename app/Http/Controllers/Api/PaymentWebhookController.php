<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookProcessor;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentWebhookProcessor $processor): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Signature');

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ApiResponse::error(ErrorCode::MALFORMED_PAYLOAD, 'Request body must be valid JSON.', 400);
        }

        $result = $processor->process($rawBody, $signature, $payload);
        $receipt = $result->receipt;

        // Never leak secrets or signatures in responses; stable failure categories only.
        [$success, $message] = ApiResponse::webhookOutcome($receipt->processing_status->value, $receipt->failure_reason);

        return ApiResponse::webhook([
            'processing_status' => $receipt->processing_status->value,
            'failure_reason' => $receipt->failure_reason,
        ], $this->httpStatus($result->httpStatus, $receipt->processing_status), $success, $message);
    }

    private function httpStatus(int $processorStatus, WebhookProcessingStatus $processing): int
    {
        // Duplicates and already-final events are safe for gateway retries: 200.
        if (in_array($processing, [
            WebhookProcessingStatus::Duplicate,
            WebhookProcessingStatus::Ignored,
            WebhookProcessingStatus::Processed,
        ], true)) {
            return 200;
        }

        // Unexpected internal failure: 500 so the gateway retries.
        if ($processing === WebhookProcessingStatus::Failed) {
            return 500;
        }

        return $processorStatus;
    }
}
