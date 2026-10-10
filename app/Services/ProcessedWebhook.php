<?php

namespace App\Services;

use App\Models\WebhookReceipt;

class ProcessedWebhook
{
    public function __construct(
        public readonly WebhookReceipt $receipt,
        public readonly int $httpStatus,
    ) {}
}
