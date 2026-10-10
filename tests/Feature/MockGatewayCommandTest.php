<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Institution;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MockGatewayCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Institution::create([
            'name' => 'Kampus Alpha',
            'code' => 'CAMPUS-ALPHA',
            'webhook_secret' => 'test-secret-alpha',
        ]);
    }

    private function createInvoice(string $number = 'INV-MOCK-001'): Invoice
    {
        return Invoice::create([
            'institution_id' => Institution::where('code', 'CAMPUS-ALPHA')->firstOrFail()->id,
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => $number,
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30),
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    public function test_dry_run_prints_signed_payload_without_sending(): void
    {
        Http::preventStrayRequests();

        $exit = Artisan::call('mock:gateway-event', [
            '--no-send' => true,
            '--event-id' => 'evt-dry-1',
            '--gateway-id' => 'gw-dry-1',
        ]);
        $output = Artisan::output();

        $this->assertEquals(0, $exit);
        $this->assertStringContainsString('payment.success', $output);
        $this->assertStringContainsString('evt-dry-1', $output);
        $this->assertStringContainsString('Dry run (--no-send): not posted.', $output);
    }

    public function test_command_posts_success_and_invoice_moves_to_paid(): void
    {
        $this->createInvoice();

        Http::fake([
            '*' => Http::response(['data' => ['processing_status' => 'processed', 'failure_reason' => null]], 200),
        ]);

        $exit = Artisan::call('mock:gateway-event', [
            '--event-id' => 'evt-mock-post-1',
            '--gateway-id' => 'gw-mock-post-1',
        ]);

        $this->assertEquals(0, $exit);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            if (! is_array($body)) {
                return false;
            }

            $expectedSignature = hash_hmac(
                'sha256',
                json_encode($body, JSON_UNESCAPED_SLASHES),
                'test-secret-alpha'
            );

            return $body['event_id'] === 'evt-mock-post-1'
                && $body['event_type'] === 'payment.success'
                && $request->header('X-Signature') === [$expectedSignature];
        });
    }

    public function test_unknown_institution_fails_with_hint(): void
    {
        Http::preventStrayRequests();

        $exit = Artisan::call('mock:gateway-event', ['--institution' => 'NOPE', '--no-send' => true]);

        $this->assertEquals(1, $exit);
        $this->assertStringContainsString('Unknown institution', Artisan::output());
    }
}
