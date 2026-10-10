<?php

namespace App\Console\Commands;

use App\Models\Institution;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Issue (or rotate) a per-institution API token for the invoice API.
 *
 * Only the SHA-256 hash is stored (institutions.api_token_hash); the
 * plaintext is printed once and must be distributed out of band.
 */
class InstitutionTokenCommand extends Command
{
    protected $signature = 'institution:token
        {code : Institution code (e.g. CAMPUS-ALPHA)}
        {--rotate : Force-rotate even if a token is already set}
        {--token= : Use this plaintext instead of generating one}';

    protected $description = 'Issue or rotate a per-institution API token (Bearer auth for invoice API)';

    public function handle(): int
    {
        $code = (string) $this->argument('code');
        $institution = Institution::where('code', $code)->first();

        if (! $institution) {
            $this->error("Unknown institution code: {$code}");

            return self::FAILURE;
        }

        if ($institution->api_token_hash && ! $this->option('rotate') && ! $this->option('token')) {
            warning("{$code} already has a token. Re-run with --rotate to replace it (old token stops working).");

            return self::FAILURE;
        }

        $plain = $this->option('token') ?: 'ukt_'.Str::random(48);
        $institution->api_token_hash = Institution::apiTokenHash($plain);
        $institution->save();

        info("Token issued for {$code}. Store it now — it cannot be read back:");
        $this->line($plain);
        warning('Only the SHA-256 hash is stored. Distribute out of band; rotate on leak.');

        return self::SUCCESS;
    }
}
