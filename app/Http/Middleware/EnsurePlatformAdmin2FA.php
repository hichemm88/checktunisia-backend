<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rend la 2FA obligatoire pour les administrateurs plateforme.
 *
 * Le compte platform_admin n'a aucun scoping tenant : il lit et écrit sur tous
 * les hôtels, tous les voyageurs, les journaux d'audit, les paiements et le
 * provisionnement des comptes autorité. Un mot de passe volé donnait jusqu'ici
 * la compromission totale de la plateforme.
 *
 * Pendant de EnsureAuthorityCredentialValid, qui impose déjà la même règle aux
 * comptes autorité. Le code d'erreur est identique pour que le frontend traite
 * les deux cas par le même chemin (redirection vers la page de configuration).
 */
class EnsurePlatformAdmin2FA
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->two_factor_confirmed_at) {
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
