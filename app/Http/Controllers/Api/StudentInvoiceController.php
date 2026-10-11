<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentInvoiceController extends Controller
{
    public function index(Request $request, string $studentNumber): JsonResponse
    {
        /** @var Institution $institution */
        $institution = $request->attributes->get('institution');

        $invoices = $institution->invoices()
            ->where('student_number', $studentNumber)
            ->orderBy('id')
            ->paginate(15);

        return ApiResponse::success(
            $invoices->map(fn ($invoice) => [
                'id' => (string) $invoice->id,
                'student_number' => $invoice->student_number,
                'semester' => $invoice->semester,
                'invoice_number' => $invoice->invoice_number,
                'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                'expires_at' => $invoice->expires_at->toIso8601String(),
                'status' => $invoice->status->value,
            ])->values(),
            'Student invoices retrieved.',
            200,
            [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        );
    }
}
