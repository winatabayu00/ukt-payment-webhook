<?php

namespace App\Http\Controllers\Api;

use App\Enums\WebhookProcessingStatus;
use App\Http\Controllers\Controller;
use App\Services\PaymentWebhookProcessor;
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
            return response()->json([
                'error' => ['code' => 'MALFORMED_PAYLOAD', 'message' => 'Request body must be valid JSON.'],
            ], 400);
        }

        $result = $processor->process($rawBody, $signature, $payload);
        $receipt = $result->receipt;

        // Never leak secrets or signatures in responses; stable failure categories only.
        return response()->json([
            'data' => [
                'processing_status' => $receipt->processing_status->value,
                'failure_reason' => $receipt->failure_reason,
            ],
        ], $this->httpStatus($result->httpStatus, $receipt->processing_status));
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
