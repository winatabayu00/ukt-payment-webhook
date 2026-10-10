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
        'api_token_hash',
    ];

    protected $hidden = [
        'webhook_secret',
        'api_token_hash',
    ];

    protected $casts = [
        'webhook_secret' => 'encrypted',
    ];

    /**
     * Hash a plaintext API token the same way it is stored.
     */
    public static function apiTokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

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
