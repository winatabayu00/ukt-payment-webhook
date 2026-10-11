<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $alpha;

    private string $token = 'test-token-envelope';

    private string $secret = 'test-secret-envelope';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Institution::create([
            'name' => 'Kampus Alpha',
            'code' => 'CAMPUS-ALPHA',
            'webhook_secret' => $this->secret,
            'api_token_hash' => Institution::apiTokenHash($this->token),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function invoicePayload(string $number = 'INV-ENV-1'): array
    {
        return [
            'student_number' => '231001',
            'semester' => '2026-1',
            'invoice_number' => $number,
            'amount' => '1500000.00',
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ];
    }

    private function webhookPayload(array $overrides = []): array
    {
        return array_merge([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_id' => 'evt-env-1',
            'event_type' => 'payment.success',
            'invoice_number' => 'INV-ENV-1',
            'gateway_transaction_id' => 'gw-env-1',
            'amount' => '1500000.00',
            'occurred_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function postWebhookRaw(string $rawBody, ?string $signature): \Illuminate\Testing\TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($signature !== null) {
            $server['HTTP_X_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/payments', [], [], [], $server, $rawBody);
    }

    public function test_invoice_create_uses_success_envelope(): void
    {
        $response = $this->postJson('/api/invoices', $this->invoicePayload(), $this->auth());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_number', 'INV-ENV-1')
            ->assertJsonPath('data.status', 'unpaid');
        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));
    }

    public function test_invoice_show_and_transactions_use_success_envelope(): void
    {
        $created = $this->postJson('/api/invoices', $this->invoicePayload(), $this->auth())->assertStatus(201);
        $id = $created->json('data.id');

        $this->getJson("/api/invoices/{$id}", $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_number', 'INV-ENV-1');

        $this->getJson("/api/invoices/{$id}/transactions", $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->getJson('/api/students/231001/invoices', $this->auth())
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data', 'meta']);
    }

    public function test_auth_errors_use_error_envelope(): void
    {
        $missing = $this->postJson('/api/invoices', $this->invoicePayload());
        $missing->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INSTITUTION_UNRESOLVED');
        $this->assertIsString($missing->json('message'));

        $unknown = $this->postJson('/api/invoices', $this->invoicePayload(), ['Authorization' => 'Bearer bogus']);
        $unknown->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INSTITUTION_UNKNOWN');
    }

    public function test_not_found_uses_error_envelope(): void
    {
        $this->getJson('/api/invoices/999999', $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_validation_failure_uses_error_envelope_with_details(): void
    {
        $payload = $this->invoicePayload();
        unset($payload['amount']);

        $response = $this->postJson('/api/invoices', $payload, $this->auth());

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['success', 'message', 'error' => ['code', 'details']]);
        $this->assertArrayHasKey('amount', $response->json('error.details'));
    }

    public function test_webhook_processed_uses_webhook_envelope(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload(), $this->auth())->assertStatus(201);

        $rawBody = json_encode($this->webhookPayload(), JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $rawBody, $this->secret);

        $response = $this->postWebhookRaw($rawBody, $signature);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.processing_status', 'processed');
        $this->assertIsString($response->json('message'));
    }

    public function test_webhook_rejected_uses_failure_envelope_with_stable_keys(): void
    {
        $this->postJson('/api/invoices', $this->invoicePayload(), $this->auth())->assertStatus(201);

        $rawBody = json_encode($this->webhookPayload(['event_id' => 'evt-env-bad']), JSON_UNESCAPED_SLASHES);

        $response = $this->postWebhookRaw($rawBody, 'bad-signature');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.processing_status', 'rejected')
            ->assertJsonPath('data.failure_reason', 'signature_invalid');
    }

    public function test_webhook_malformed_json_uses_error_envelope_without_leak(): void
    {
        $response = $this->postWebhookRaw('{not-json', null);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'MALFORMED_PAYLOAD');

        $content = (string) $response->getContent();
        $this->assertStringNotContainsString($this->secret, $content);
        $this->assertStringNotContainsString($this->token, $content);
    }
}
