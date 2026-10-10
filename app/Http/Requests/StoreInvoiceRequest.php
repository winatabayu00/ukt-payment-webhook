<?php

namespace App\Http\Requests;

use App\Models\Institution;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('institution') instanceof Institution;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Institution|null $institution */
        $institution = $this->attributes->get('institution');

        return [
            'student_number' => ['required', 'string', 'max:64'],
            // Semester format YYYY-S, e.g. 2026-1 (odd) or 2026-2 (even).
            'semester' => ['required', 'string', 'regex:/^\d{4}-[12]$/'],
            'invoice_number' => [
                'required',
                'string',
                'max:64',
                Rule::unique('invoices', 'invoice_number')
                    ->where('institution_id', $institution?->id),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'expires_at' => ['required', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'semester.regex' => 'Semester must use the format YYYY-S, for example 2026-1.',
            'invoice_number.unique' => 'Invoice number is already used within this institution.',
        ];
    }
}
