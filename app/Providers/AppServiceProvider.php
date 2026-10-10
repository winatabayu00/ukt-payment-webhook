<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Authenticated invoice traffic, keyed per institution API token.
        RateLimiter::for('api-invoices', function (Request $request) {
            return Limit::perMinute((int) config('api.invoice_rate_limit', 60))
                ->by('institution-token:'.sha1((string) $request->bearerToken()));
        });

        // HMAC webhook traffic is unauthenticated at the HTTP layer,
        // so it is keyed by client IP.
        RateLimiter::for('api-webhooks', function (Request $request) {
            return Limit::perMinute((int) config('api.webhook_rate_limit', 300))
                ->by('webhook-ip:'.$request->ip());
        });
    }
}
