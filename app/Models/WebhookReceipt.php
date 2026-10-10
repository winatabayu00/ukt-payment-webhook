<?php

namespace App\Models;

use App\Enums\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookReceipt extends Model
{
    protected $fillable = [
        'institution_id',
        'event_id',
        'event_type',
        'invoice_number',
        'signature_valid',
        'processing_status',
        'payload_redacted',
        'failure_reason',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'payload_redacted' => 'array',
        'processing_status' => WebhookProcessingStatus::class,
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
