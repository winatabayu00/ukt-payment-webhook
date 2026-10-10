<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the current institution (tenant) from the trusted
 * X-Institution-Code credential header.
 *
 * This is a demo-grade credential mechanism for the technical test:
 * in production this must be replaced by real authentication (tokens,
 * signatures, or an identity provider). The institution resolved here
 * is the only tenant scope used by the invoice API; any institution_id
 * supplied in the request body is ignored.
 */
class ResolveInstitution
{
    public const HEADER = 'X-Institution-Code';

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->header(self::HEADER);

        if (! is_string($code) || trim($code) === '') {
            return response()->json([
                'error' => [
                    'code' => 'INSTITUTION_UNRESOLVED',
                    'message' => 'Missing '.self::HEADER.' header.',
                ],
            ], 401);
        }

        $institution = Institution::where('code', trim($code))->first();

        if (! $institution) {
            return response()->json([
                'error' => [
                    'code' => 'INSTITUTION_UNKNOWN',
                    'message' => 'Unknown institution.',
                ],
            ], 401);
        }

        $request->attributes->set('institution', $institution);
        app()->instance('currentInstitution', $institution);

        return $next($request);
    }
}
