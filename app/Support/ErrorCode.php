<?php

namespace App\Support;

/**
 * Stable error codes for the standard /api/* error envelope.
 *
 * Codes are part of the public contract (openapi v1.4.0 ErrorEnvelope);
 * never rename a code — add a new one instead. Used with ApiResponse::error().
 */
final class ErrorCode
{
    public const INSTITUTION_UNRESOLVED = 'INSTITUTION_UNRESOLVED';

    public const INSTITUTION_UNKNOWN = 'INSTITUTION_UNKNOWN';

    public const NOT_FOUND = 'NOT_FOUND';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const MALFORMED_PAYLOAD = 'MALFORMED_PAYLOAD';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public const CONFLICT = 'CONFLICT';

    public const CSRF_TOKEN_MISMATCH = 'CSRF_TOKEN_MISMATCH';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const REQUEST_FAILED = 'REQUEST_FAILED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
}
