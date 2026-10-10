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

];
