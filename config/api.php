<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Named limiters used by routes/api.php. Invoice routes are authenticated
    | per institution token, so they are keyed by token; the HMAC webhook is
    | unauthenticated at the HTTP layer, so it is keyed by client IP.
    | Override per environment via .env.
    |
    */

    'invoice_rate_limit' => (int) env('API_INVOICE_RATE_LIMIT', 60),

    'webhook_rate_limit' => (int) env('API_WEBHOOK_RATE_LIMIT', 300),

    /*
    |--------------------------------------------------------------------------
    | Webhook occurred_at staleness window
    |--------------------------------------------------------------------------
    |
    | Gateway timestamps outside this window are rejected 422 instead of
    | moving money: too far in the past (replay/stale feed) or too far in
    | the future (clock skew / bad sender clock). Override per environment.
    |
    */

    'webhook_occurred_at_max_past_days' => (int) env('WEBHOOK_OCCURRED_AT_MAX_PAST_DAYS', 30),

    'webhook_occurred_at_max_future_minutes' => (int) env('WEBHOOK_OCCURRED_AT_MAX_FUTURE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Webhook receipt retention (days)
    |--------------------------------------------------------------------------
    |
    | Default window used by `php artisan webhook:prune` to delete old
    | terminal receipts. Failed receipts are kept unless --include-failed.
    |
    */

    'webhook_receipt_retention_days' => (int) env('WEBHOOK_RECEIPT_RETENTION_DAYS', 90),

];
