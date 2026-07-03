<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block access to protected routes when the user holds only a 2fa-pending token.
 *
 * A 2fa-pending token is issued after successful password authentication for users
 * who have configured TOTP. It grants access ONLY to POST /auth/2fa/verify.
 * All other routes must reject it.
 */
class Require2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->can('2fa-pending') && !$token->can('*')) {
            return response()->json([
                'data'   => null,
                'errors' => [[
                    'code'    => '2FA_PENDING',
                    'message' => 'Two-factor authentication required. POST /api/v1/auth/2fa/verify',
                    'field'   => null,
                ]],
            ], 403);
        }

        return $next($request);
    }
}
