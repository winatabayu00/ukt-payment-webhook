<?php

namespace App\Support;

use App\Services\WebhookSignatureVerifier;
use Carbon\CarbonImmutable;

/**
 * Local mock payment-gateway event builder.
 *
 * Produces contract-valid payloads for POST /api/webhooks/payments
 * (see contracts/openapi.yaml) plus the matching X-Signature header.
 * Never reads secrets itself; the caller supplies the institution
 * webhook_secret (e.g. from DB or --secret option).
 */
class MockGatewayEventBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
        private readonly WebhookSignatureVerifier $verifier = new WebhookSignatureVerifier,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function create(array $overrides = []): self
    {
        $now = CarbonImmutable::now()->toIso8601String();

        $payload = array_merge([
            'institution_code' => 'CAMPUS-ALPHA',
            'event_type' => 'payment.success',
            'event_id' => 'evt-mock-'.substr(md5(uniqid('', true)), 0, 8),
            'invoice_number' => 'INV-MOCK-001',
            'gateway_transaction_id' => 'gw-mock-'.substr(md5(uniqid('', true)), 0, 8),
            'amount' => '1500000.00',
            'occurred_at' => $now,
        ], $overrides);

        // Normalize shorthand event names used by the artisan command.
        if (($payload['event_type'] ?? null) === 'success') {
            $payload['event_type'] = 'payment.success';
        }
        if (($payload['event_type'] ?? null) === 'expired') {
            $payload['event_type'] = 'payment.expired';
        }

        return new self($payload);
    }

    public static function success(array $overrides = []): self
    {
        return self::create(array_merge(['event_type' => 'payment.success'], $overrides));
    }

    public static function expired(array $overrides = []): self
    {
        return self::create(array_merge(['event_type' => 'payment.expired'], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Raw JSON body, byte-for-byte what gets signed and sent.
     */
    public function rawBody(): string
    {
        $raw = json_encode($this->payload, JSON_UNESCAPED_SLASHES);

        return $raw === false ? '{}' : $raw;
    }

    /**
     * Lowercase hex HMAC-SHA256 over the raw body.
     */
    public function signature(string $secret): string
    {
        return $this->verifier->sign($this->rawBody(), $secret);
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $secret): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Signature' => $this->signature($secret),
        ];
    }

    /**
     * Ready-to-paste curl example for docs / --print-only output.
     */
    public function asCurl(string $url, string $secret): string
    {
        $raw = $this->rawBody();
        $sig = $this->signature($secret);

        // Escape single quotes for shell safety.
        $escaped = str_replace("'", "'\\''", $raw);

        return "curl -i -X POST '{$url}'"
            ." -H 'Content-Type: application/json'"
            ." -H 'X-Signature: {$sig}'"
            ." -d '{$escaped}'";
    }
}
