<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Institution;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /**
     * Create an invoice strictly inside the resolved institution scope.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Invoice|Institution $scope, array $data): Invoice
    {
        $institution = $scope instanceof Invoice ? $scope->institution : $scope;

        return DB::transaction(function () use ($institution, $data) {
            $invoice = new Invoice([
                'student_number' => $data['student_number'],
                'semester' => $data['semester'],
                'invoice_number' => $data['invoice_number'],
                'amount' => $data['amount'],
                'expires_at' => $data['expires_at'],
                'status' => InvoiceStatus::Unpaid,
            ]);
            $invoice->institution()->associate($institution);
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Persist a validated invoice payload, surfacing tenant-scoped
     * duplicates as a 409-style validation error.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromValidated(Institution $institution, array $data): Invoice
    {
        try {
            return $this->create($institution, $data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                throw ValidationException::withMessages([
                    'invoice_number' => 'Invoice number is already used within this institution.',
                ]);
            }

            throw $e;
        }
    }

    private function isDuplicateKey(\Illuminate\Database\QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || ($e->errorInfo[0] ?? null) === '23000';
    }
}
