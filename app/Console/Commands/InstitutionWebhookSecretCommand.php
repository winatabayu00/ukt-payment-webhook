<?php

namespace App\Console\Commands;

use App\Models\Institution;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Issue (or rotate) the per-institution gateway webhook secret.
 *
 * The plaintext secret is needed at runtime to verify HMAC-SHA256
 * X-Signature headers, so it is stored encrypted-at-rest (the model's
 * `encrypted` cast, keyed by APP_KEY). The plaintext is printed once and
 * must be distributed to the gateway out of band.
 */
class InstitutionWebhookSecretCommand extends Command
{
    protected $signature = 'institution:webhook-secret'
        .' {code : Institution code (e.g. CAMPUS-ALPHA)}'
        .' {--rotate : Force-rotate even if a secret is already set}'
        .' {--secret= : Use this plaintext instead of generating one}';

    protected $description = 'Issue or rotate a per-institution gateway webhook secret (HMAC verification)';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $institution = Institution::where('code', $code)->first();

        if (! $institution) {
            $this->error("Unknown institution code: {$code}");

            return self::FAILURE;
        }

        if ($institution->webhook_secret && ! $this->option('rotate') && ! $this->option('secret')) {
            warning("{$code} already has a webhook secret. Re-run with --rotate to replace it (old gateway signatures stop working).");

            return self::FAILURE;
        }

        $plain = $this->option('secret') ?: 'whsec_'.Str::random(48);
        $institution->webhook_secret = $plain;
        $institution->save();

        info("Webhook secret issued for {$code}. Update the gateway now — it cannot be read back:");
        $this->line($plain);
        warning('Stored encrypted-at-rest. Distribute to the gateway out of band; rotate on leak.');

        return self::SUCCESS;
    }
}
