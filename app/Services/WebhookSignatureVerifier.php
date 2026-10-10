<?php

namespace App\Services;

/**
 * Verify HMAC-SHA256 signatures over the raw request body.
 */
class WebhookSignatureVerifier
{
    /**
     * Build the expected lowercase hex signature.
     */
    public function sign(string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Constant-time comparison of the provided X-Signature value.
     */
    public function verify(string $rawBody, string $secret, ?string $provided): bool
    {
        if (! is_string($provided) || trim($provided) === '') {
            return false;
        }

        $expected = $this->sign($rawBody, $secret);

        return hash_equals($expected, strtolower(trim($provided)));
    }
}
