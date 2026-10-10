<?php

namespace App\Enums;

enum TransitionOutcome: string
{
    case MarkPaid = 'mark_paid';
    case MarkExpired = 'mark_expired';
    case IgnoreAlreadyFinal = 'ignore_already_final';
    case ConflictAlreadyPaid = 'conflict_already_paid';
}
