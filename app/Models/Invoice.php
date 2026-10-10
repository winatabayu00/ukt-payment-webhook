<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'institution_id',
        'student_number',
        'semester',
        'invoice_number',
        'amount',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'status' => InvoiceStatus::class,
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /** Scope every invoice query to the owning institution. */
    public function scopeForInstitution(Builder $query, Institution $institution): Builder
    {
        return $query->where('institution_id', $institution->id);
    }
}
