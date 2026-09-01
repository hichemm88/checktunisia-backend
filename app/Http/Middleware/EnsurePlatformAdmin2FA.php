<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionIssuer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rend une authentification à deux facteurs obligatoire pour les
 * administrateurs plateforme.
 *
 * Le compte platform_admin n'a aucun scoping tenant : il lit et écrit sur tous
 * les hôtels, tous les voyageurs, les journaux d'audit, les paiements et le
 * provisionnement des comptes autorité. Un mot de passe volé donnait jusqu'ici
 * la compromission totale de la plateforme.
 *
 * Deux facteurs valent ici : la TOTP configurée, OU une session ouverte par
 * passkey. Une passkey vérifiée prouve déjà la possession de l'appareil et la
 * présence de son porteur (biométrie ou code) : exiger un TOTP par-dessus
 * serait un troisième facteur, pas un second — et forcerait l'administrateur à
 * garder une application d'authentification qu'il n'utilise plus.
 *
 * Pendant de EnsureAuthorityCredentialValid, qui impose la même règle aux
 * comptes autorité. Le code d'erreur est identique pour que le frontend traite
 * les deux cas par le même chemin (redirection vers la page de configuration).
 */
class EnsurePlatformAdmin2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (SessionIssuer::isPasskeySession($user?->currentAccessToken())) {
            return $next($request);
        }

        if (!$user?->two_factor_confirmed_at) {
            return response()->json([
                'data'   => null,
                'errors' => [[
                    'code'    => '2FA_SETUP_REQUIRED',
                    'message' => 'Two-factor authentication must be configured before accessing the admin area.',
                    'field'   => null,
                ]],
            ], 403);
        }

        return $next($request);
    }
}
