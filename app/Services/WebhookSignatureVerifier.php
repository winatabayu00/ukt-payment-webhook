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
     *
     * Accepts plain lowercase hex as well as the common `sha256=<hex>`
     * prefix form (case-insensitive) some gateways send.
     */
    public function verify(string $rawBody, string $secret, ?string $provided): bool
    {
        if (! is_string($provided) || trim($provided) === '') {
            return false;
        }

        $clean = strtolower(trim($provided));
        if (str_starts_with($clean, 'sha256=')) {
            $clean = trim(substr($clean, strlen('sha256=')));
        }

        if ($clean === '') {
            return false;
        }

        $expected = $this->sign($rawBody, $secret);

        return hash_equals($expected, $clean);
    }
}
