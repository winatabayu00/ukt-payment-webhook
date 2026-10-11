<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Institution;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request, InvoiceService $service): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        $invoice = $service->createFromValidated($institution, $request->validated());

        return ApiResponse::success($this->serialize($invoice->fresh()), 'Invoice created.', 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        // Hide cross-tenant existence: 404 when the invoice belongs elsewhere.
        if ((int) $invoice->institution_id !== (int) $institution->id) {
            return $this->notFound();
        }

        return ApiResponse::success($this->serialize($invoice), 'Invoice retrieved.');
    }

    public function transactions(Request $request, Invoice $invoice): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        if ((int) $invoice->institution_id !== (int) $institution->id) {
            return $this->notFound();
        }

        $transactions = $invoice->paymentTransactions()
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => [
                'id' => (string) $t->id,
                'gateway_transaction_id' => $t->gateway_transaction_id,
                'event_type' => $t->event_type->value,
                'amount' => number_format((float) $t->amount, 2, '.', ''),
                'occurred_at' => $t->occurred_at?->toIso8601String(),
            ]);

        return ApiResponse::success($transactions, 'Transactions retrieved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->id,
            'student_number' => $invoice->student_number,
            'semester' => $invoice->semester,
            'invoice_number' => $invoice->invoice_number,
            'amount' => number_format((float) $invoice->amount, 2, '.', ''),
            'expires_at' => $invoice->expires_at->toIso8601String(),
            'status' => $invoice->status->value,
        ];
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error(ErrorCode::NOT_FOUND, 'Resource not found.', 404);
    }
}
