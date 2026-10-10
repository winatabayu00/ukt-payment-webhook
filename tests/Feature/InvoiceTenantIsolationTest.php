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

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Institution::create([
            'name' => 'Kampus Alpha',
            'code' => 'CAMPUS-ALPHA',
            'webhook_secret' => 'test-secret-alpha',
        ]);
        $this->beta = Institution::create([
            'name' => 'Kampus Beta',
            'code' => 'CAMPUS-BETA',
            'webhook_secret' => 'test-secret-beta',
        ]);
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

    public function test_missing_institution_header_is_rejected(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INSTITUTION_UNRESOLVED');
    }

    public function test_unknown_institution_code_is_rejected(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload(), [
            'X-Institution-Code' => 'NOPE',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INSTITUTION_UNKNOWN');
    }

    public function test_create_invoice_is_scoped_to_resolved_institution(): void
    {
        $response = $this->postJson('/api/invoices', $this->invoicePayload('INV-A1'), [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(201);

        $response->assertJsonPath('data.invoice_number', 'INV-A1');
        $response->assertJsonPath('data.status', 'unpaid');

        $this->assertDatabaseHas('invoices', [
            'institution_id' => $this->alpha->id,
            'invoice_number' => 'INV-A1',
        ]);
    }

    public function test_invoice_number_unique_per_institution_but_reusable_across_tenants(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(201);

        // Same number, same tenant: rejected.
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(422);

        // Same number, other tenant: allowed.
        $this->postJson('/api/invoices', $this->invoicePayload('INV-DUP'), [
            'X-Institution-Code' => 'CAMPUS-BETA',
        ])->assertStatus(201);

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

        $this->getJson("/api/invoices/{$invoice->id}", [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(200)->assertJsonPath('data.invoice_number', 'INV-X1');

        $this->getJson("/api/invoices/{$invoice->id}", [
            'X-Institution-Code' => 'CAMPUS-BETA',
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
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

        $alpha = $this->getJson('/api/students/99001/invoices', [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(200);
        $this->assertEquals(2, $alpha->json('meta.total'));

        $beta = $this->getJson('/api/students/99001/invoices', [
            'X-Institution-Code' => 'CAMPUS-BETA',
        ])->assertStatus(200);
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

        $this->getJson("/api/invoices/{$invoice->id}/transactions", [
            'X-Institution-Code' => 'CAMPUS-ALPHA',
        ])->assertStatus(200)->assertJsonPath('data', []);

        $this->getJson("/api/invoices/{$invoice->id}/transactions", [
            'X-Institution-Code' => 'CAMPUS-BETA',
        ])->assertStatus(404);
    }
}
