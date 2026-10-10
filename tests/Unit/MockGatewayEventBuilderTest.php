<?php

namespace Tests\Unit;

use App\Support\MockGatewayEventBuilder;
use PHPUnit\Framework\TestCase;

class MockGatewayEventBuilderTest extends TestCase
{
    public function test_success_payload_matches_openapi_contract_and_signs_raw_body(): void
    {
        $mock = MockGatewayEventBuilder::success([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_id' => 'evt-mock-1',
            'invoice_number' => 'INV-MOCK-001',
            'gateway_transaction_id' => 'gw-mock-1',
            'amount' => '1500000.00',
        ]);

        $payload = $mock->payload();

        $this->assertEquals('CAMPUS-ALPHA', $payload['institution_code']);
        $this->assertEquals('payment.success', $payload['event_type']);
        $this->assertEquals('evt-mock-1', $payload['event_id']);
        $this->assertEquals('INV-MOCK-001', $payload['invoice_number']);
        $this->assertEquals('gw-mock-1', $payload['gateway_transaction_id']);
        $this->assertEquals('1500000.00', $payload['amount']);
        $this->assertArrayHasKey('occurred_at', $payload);

        $secret = 'test-secret-alpha';
        $expected = hash_hmac('sha256', $mock->rawBody(), $secret);

        $this->assertEquals($expected, $mock->signature($secret));
        $this->assertEquals($expected, $mock->headers($secret)['X-Signature']);
    }

    public function test_expired_shorthand_normalizes_to_contract_event_type(): void
    {
        $mock = MockGatewayEventBuilder::expired([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_id' => 'evt-mock-exp',
            'invoice_number' => 'INV-MOCK-001',
            'gateway_transaction_id' => 'gw-mock-exp',
            'amount' => '1500000.00',
        ]);

        $this->assertEquals('payment.expired', $mock->payload()['event_type']);

        $shorthand = MockGatewayEventBuilder::create(['event_type' => 'expired']);
        $this->assertEquals('payment.expired', $shorthand->payload()['event_type']);
    }

    public function test_curl_output_contains_signature_and_payload(): void
    {
        $mock = MockGatewayEventBuilder::success([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_id' => 'evt-mock-curl',
            'invoice_number' => 'INV-MOCK-001',
            'gateway_transaction_id' => 'gw-mock-curl',
            'amount' => '1500000.00',
        ]);

        $curl = $mock->asCurl('http://127.0.0.1:8000/api/webhooks/payments', 's3cret');

        $this->assertStringContainsString('X-Signature:', $curl);
        $this->assertStringContainsString('evt-mock-curl', $curl);
        $this->assertStringContainsString(hash_hmac('sha256', $mock->rawBody(), 's3cret'), $curl);
    }
}
