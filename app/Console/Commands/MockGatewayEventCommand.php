<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\MockGatewayEventBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Local mock payment-gateway sender.
 *
 * Builds a contract-valid webhook payload, signs it with the
 * institution webhook_secret, and POSTs it to /api/webhooks/payments.
 * Demo/local use only — never a real gateway.
 */
class MockGatewayEventCommand extends Command
{
    protected $signature = 'mock:gateway-event'
        .' {--event=success : success|expired (payment.success|payment.expired)}'
        .' {--institution=CAMPUS-ALPHA : institution code}'
        .' {--invoice= : invoice number (default INV-MOCK-001)}'
        .' {--amount= : amount override (default 1500000.00)}'
        .' {--event-id= : event_id override (default random evt-mock-*)}'
        .' {--gateway-id= : gateway_transaction_id override (default random gw-mock-*)}'
        .' {--secret= : webhook secret override (default: DB lookup by institution code)}'
        .' {--url= : full webhook URL (default APP_URL + /api/webhooks/payments)}'
        .' {--invalid-signature : send a bad signature to demo the 401 path}'
        .' {--no-send : build and print only, do not POST}'
        .' {--print-curl : also print a curl equivalent}';

    protected $description = 'Send a mock payment.success/payment.expired webhook to the local app';

    public function handle(): int
    {
        $event = strtolower((string) $this->option('event'));
        if (! in_array($event, ['success', 'expired', 'payment.success', 'payment.expired'], true)) {
            $this->error("Invalid --event '{$event}'. Use success|expired.");

            return self::FAILURE;
        }

        $institutionCode = (string) $this->option('institution');
        $secret = (string) ($this->option('secret') ?? '');

        if ($secret === '') {
            $institution = Institution::where('code', $institutionCode)->first();
            if (! $institution) {
                $this->error("Unknown institution '{$institutionCode}'. Run: php artisan migrate:fresh --seed");

                return self::FAILURE;
            }
            $secret = $institution->webhook_secret;
        }

        $overrides = ['institution_code' => $institutionCode];
        $overrides['event_type'] = $event === 'expired' || $event === 'payment.expired'
            ? 'payment.expired'
            : 'payment.success';

        foreach ([
            'invoice' => 'invoice_number',
            'amount' => 'amount',
            'event-id' => 'event_id',
            'gateway-id' => 'gateway_transaction_id',
        ] as $option => $field) {
            $value = $this->option($option);
            if (is_string($value) && $value !== '') {
                $overrides[$field] = $value;
            }
        }

        $mock = MockGatewayEventBuilder::create($overrides);
        $rawBody = $mock->rawBody();
        $signature = $this->option('invalid-signature')
            ? 'invalid-signature-demo'
            : $mock->signature($secret);

        $url = (string) ($this->option('url') ?? '');
        if ($url === '') {
            $url = rtrim((string) config('app.url', 'http://127.0.0.1:8000'), '/').'/api/webhooks/payments';
        }

        $this->info("Event: {$mock->payload()['event_type']}  institution: {$institutionCode}");
        $this->line("URL: {$url}");
        $this->line("Payload: {$rawBody}");
        $this->line($this->option('invalid-signature')
            ? 'Signature: invalid-signature-demo (expect 401 signature_invalid)'
            : "X-Signature: {$signature}");

        if ($this->option('print-curl') || $this->option('no-send')) {
            $this->line($mock->asCurl($url, $this->option('invalid-signature') ? 'wrong-secret' : $secret));
        }

        if ($this->option('no-send')) {
            $this->comment('Dry run (--no-send): not posted.');

            return self::SUCCESS;
        }

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Signature' => $signature,
        ])->withBody($rawBody, 'application/json')->post($url);

        $this->info("HTTP {$response->status()}");
        $this->line($response->body());

        if (! $response->successful() && in_array($response->status(), [401, 422], true)) {
            $this->comment('Gateway-style hint: 4xx means rejected (see failure_reason above). Replay with fixed fields.');
        }

        return self::SUCCESS;
    }
}
