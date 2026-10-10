<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Institution;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function store(StoreInvoiceRequest $request, InvoiceService $service): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        $invoice = $service->createFromValidated($institution, $request->validated());

        return response()->json(['data' => $this->serialize($invoice->fresh())], 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        // Hide cross-tenant existence: 404 when the invoice belongs elsewhere.
        if ((int) $invoice->institution_id !== (int) $institution->id) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->serialize($invoice)]);
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

        return response()->json(['data' => $transactions]);
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
        return response()->json([
            'error' => ['code' => 'NOT_FOUND', 'message' => 'Resource not found.'],
        ], 404);
    }
}
