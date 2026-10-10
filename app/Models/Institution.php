<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    protected $fillable = [
        'name',
        'code',
        'webhook_secret',
    ];

    protected $casts = [
        'webhook_secret' => 'encrypted',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function webhookReceipts(): HasMany
    {
        return $this->hasMany(WebhookReceipt::class);
    }
}
