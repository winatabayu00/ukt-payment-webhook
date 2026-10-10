<?php

namespace Database\Seeders;

use App\Models\Institution;
use Illuminate\Database\Seeder;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        // Demo-only secrets for local/test. Never real production secrets.
        $institutions = [
            ['name' => 'Kampus Alpha', 'code' => 'CAMPUS-ALPHA', 'webhook_secret' => 'demo-secret-alpha-please-rotate'],
            ['name' => 'Kampus Beta', 'code' => 'CAMPUS-BETA', 'webhook_secret' => 'demo-secret-beta-please-rotate'],
            ['name' => 'Kampus Gamma', 'code' => 'CAMPUS-GAMMA', 'webhook_secret' => 'demo-secret-gamma-please-rotate'],
        ];

        foreach ($institutions as $data) {
            Institution::updateOrCreate(
                ['code' => $data['code']],
                ['name' => $data['name'], 'webhook_secret' => $data['webhook_secret']]
            );
        }
    }
}
