<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the current institution (tenant) from its per-institution
 * API token, presented as an `Authorization: Bearer <token>` header.
 *
 * Only the SHA-256 hash of the token is stored (institutions.api_token_hash);
 * the plaintext token is never persisted and must be distributed to the
 * institution out of band, then rotated if leaked. The institution resolved
 * here is the only tenant scope used by the invoice API; any institution_id
 * supplied in the request body is ignored.
 */
class ResolveInstitution
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || trim($token) === '') {
            return response()->json([
                'error' => [
                    'code' => 'INSTITUTION_UNRESOLVED',
                    'message' => 'Missing Authorization: Bearer <api-token> header.',
                ],
            ], 401);
        }

        $candidate = Institution::apiTokenHash(trim($token));
        $institution = Institution::where('api_token_hash', $candidate)->first();

        if (! $institution || ! hash_equals((string) $institution->api_token_hash, $candidate)) {
            return response()->json([
                'error' => [
                    'code' => 'INSTITUTION_UNKNOWN',
                    'message' => 'Unknown or revoked API token.',
                ],
            ], 401);
        }

        $request->attributes->set('institution', $institution);
        app()->instance('currentInstitution', $institution);

        return $next($request);
    }
}
