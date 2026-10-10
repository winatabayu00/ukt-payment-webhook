<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        // Demo-only secrets for local/test. Never real production secrets.
        // api_token values are demo plaintext; only the SHA-256 hash is stored.
        $institutions = [
            ['name' => 'Kampus Alpha', 'code' => 'CAMPUS-ALPHA', 'webhook_secret' => 'demo-secret-alpha-please-rotate', 'api_token' => 'demo-token-alpha-please-rotate'],
            ['name' => 'Kampus Beta', 'code' => 'CAMPUS-BETA', 'webhook_secret' => 'demo-secret-beta-please-rotate', 'api_token' => 'demo-token-beta-please-rotate'],
            ['name' => 'Kampus Gamma', 'code' => 'CAMPUS-GAMMA', 'webhook_secret' => 'demo-secret-gamma-please-rotate', 'api_token' => 'demo-token-gamma-please-rotate'],
        ];

        foreach ($institutions as $data) {
            Institution::updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'webhook_secret' => $data['webhook_secret'],
                    'api_token_hash' => Institution::apiTokenHash($data['api_token']),
                ]
            );
        }
    }
}
