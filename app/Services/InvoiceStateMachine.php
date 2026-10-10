<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentEventType;
use App\Enums\TransitionOutcome;

class InvoiceStateMachine
{
    public function decide(InvoiceStatus $current, PaymentEventType $event): TransitionOutcome
    {
        return match (true) {
            $event === PaymentEventType::Success && $current === InvoiceStatus::Unpaid => TransitionOutcome::MarkPaid,
            $event === PaymentEventType::Expired && $current === InvoiceStatus::Unpaid => TransitionOutcome::MarkExpired,
            // Duplicate or already-final events must not move the state backwards.
            $event === PaymentEventType::Expired && $current === InvoiceStatus::Paid => TransitionOutcome::IgnoreAlreadyFinal,
            $event === PaymentEventType::Expired && $current === InvoiceStatus::Expired => TransitionOutcome::IgnoreAlreadyFinal,
            // A late success for an expired invoice is a domain conflict:
            // the invoice stays expired and needs manual handling (out of scope).
            $event === PaymentEventType::Success && $current === InvoiceStatus::Expired => TransitionOutcome::ConflictAlreadyPaid,
            // A duplicate success for a paid invoice is idempotent.
            $event === PaymentEventType::Success && $current === InvoiceStatus::Paid => TransitionOutcome::IgnoreAlreadyFinal,
        };
    }
}
