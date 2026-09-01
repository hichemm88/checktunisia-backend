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

        // Deux facteurs obligatoires : TOTP configurée, OU session ouverte par
        // passkey (possession de l'appareil + biométrie/code déjà vérifiées).
        // Sans la seconde branche, un agent connecté par Face ID se verrait
        // renvoyer vers la configuration TOTP alors qu'il vient de présenter
        // un facteur plus fort.
        if (!\App\Services\Auth\SessionIssuer::isPasskeySession($user->currentAccessToken())
            && !$user->two_factor_confirmed_at) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => '2FA_SETUP_REQUIRED', 'message' => 'Two-factor authentication must be configured before accessing this resource.', 'field' => null]],
            ], 403);
        }

        return $next($request);
    }
}
