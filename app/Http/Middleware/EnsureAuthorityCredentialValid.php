<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthorityCredentialValid
{
    public function handle(Request $request, Closure $next): Response
    {
        $user    = $request->user();
        $profile = $user?->authorityProfile()->first();

        if (!$profile) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'AUTHORITY_PROFILE_MISSING', 'message' => 'No authority profile found for this account.', 'field' => null]],
            ], 403);
        }

        if ($profile->isExpired()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'AUTHORITY_CREDENTIAL_EXPIRED', 'message' => 'Your authority credentials have expired. Contact your administrator.', 'field' => null]],
            ], 403);
        }

        return $next($request);
    }
}
