<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $alpha;

    private Institution $beta;

    private string $alphaToken = 'test-token-alpha';

    private string $betaToken = 'test-token-beta';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Institution::create([
            'name' => 'Kampus Alpha',
            'code' => 'CAMPUS-ALPHA',
            'webhook_secret' => 'test-secret-alpha',
            'api_token_hash' => Institution::apiTokenHash($this->alphaToken),
        ]);
        $this->beta = Institution::create([
            'name' => 'Kampus Beta',
            'code' => 'CAMPUS-BETA',
            'webhook_secret' => 'test-secret-beta',
            'api_token_hash' => Institution::apiTokenHash($this->betaToken),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function invoicePayload(string $invoiceNumber = 'INV-001'): array
    {
        return [
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => $invoiceNumber,
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ];
    }

    public function test_missing_bearer_token_is_rejected(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INSTITUTION_UNRESOLVED');
    }

    public function test_unknown_bearer_token_is_rejected(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload(), $this->auth('bogus-token'))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INSTITUTION_UNKNOWN');
    }

    public function test_plaintext_token_is_never_stored(): void
    {
        $this->assertDatabaseMissing('institutions', [
            'code' => 'CAMPUS-ALPHA',
            'api_token_hash' => $this->alphaToken,
        ]);
        $this->assertSame(
            Institution::apiTokenHash($this->alphaToken),
            $this->alpha->fresh()->api_token_hash
        );
    }

    public function test_create_invoice_is_scoped_to_resolved_institution(): void
    {
        $response = $this->postJson('/api/invoices', $this->invoicePayload('INV-A1'), $this->auth($this->alphaToken))
            ->assertStatus(201);

        $response->assertJsonPath('data.invoice_number', 'INV-A1');
        $response->assertJsonPath('data.status', 'unpaid');

        $this->assertDatabaseHas('invoices', [
            'institution_id' => $this->alpha->id,
            'invoice_number' => 'INV-A1',
        ]);
    }

    public function test_invoice_number_unique_per_institution_but_reusable_across_tenants(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), $this->auth($this->alphaToken))
            ->assertStatus(201);

        // Same number, same tenant: rejected.
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), $this->auth($this->alphaToken))
            ->assertStatus(422);

        // Same number, other tenant: allowed.
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), $this->auth($this->betaToken))
            ->assertStatus(201);

        $this->assertEquals(1, Invoice::where('institution_id', $this->alpha->id)->where('invoice_number', 'INV-DUP')->count());
        $this->assertEquals(1, Invoice::where('institution_id', $this->beta->id)->where('invoice_number', 'INV-DUP')->count());
    }

    public function test_show_hides_cross_tenant_invoice_as_404(): void
    {
        $invoice = Invoice::create([
            'institution_id' => $this->alpha->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => 'INV-X1',
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30),
            'status' => 'unpaid',
        ]);

        $this->getJson("/api/invoices/{$invoice->id}", $this->auth($this->alphaToken))
            ->assertStatus(200)->assertJsonPath('data.invoice_number', 'INV-X1');

        $this->getJson("/api/invoices/{$invoice->id}", $this->auth($this->betaToken))
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_student_invoices_are_tenant_scoped(): void
    {
        foreach (['INV-S1', 'INV-S2'] as $number) {
            Invoice::create([
                'institution_id' => $this->alpha->id,
                'student_number' => '99001',
                'semester' => '2026-1',
                'invoice_number' => $number,
                'amount' => '1000000.00',
                'expires_at' => now()->addDays(30),
                'status' => 'unpaid',
            ]);
        }
        Invoice::create([
            'institution_id' => $this->beta->id,
            'student_number' => '99001',
            'semester' => '2026-1',
            'invoice_number' => 'INV-S1',
            'amount' => '1000000.00',
            'expires_at' => now()->addDays(30),
            'status' => 'unpaid',
        ]);

        $alpha = $this->getJson('/api/students/99001/invoices', $this->auth($this->alphaToken))
            ->assertStatus(200);
        $this->assertEquals(2, $alpha->json('meta.total'));

        $beta = $this->getJson('/api/students/99001/invoices', $this->auth($this->betaToken))
            ->assertStatus(200);
        $this->assertEquals(1, $beta->json('meta.total'));
    }

    public function test_transactions_endpoint_hides_cross_tenant_ledger(): void
    {
        $invoice = Invoice::create([
            'institution_id' => $this->alpha->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => 'INV-T1',
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30),
            'status' => 'unpaid',
        ]);

        $this->getJson("/api/invoices/{$invoice->id}/transactions", $this->auth($this->alphaToken))
            ->assertStatus(200)->assertJsonPath('data', []);

        $this->getJson("/api/invoices/{$invoice->id}/transactions", $this->auth($this->betaToken))
            ->assertStatus(404);
    }
}
