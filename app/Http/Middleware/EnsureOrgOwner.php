<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réservé au propriétaire de l'organisation (users.role_org = 'owner').
 *
 * Applique la matrice de permissions côté API : onboarding, gestion des
 * établissements, facturation, gestion des utilisateurs et transfert
 * d'ownership sont owner-only. Les hotel_admin « admin » reçoivent un 403
 * avec le code ROLE_ORG_FORBIDDEN (jamais une simple redirection front).
 */
class EnsureOrgOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // role_org null = compte d'avant la migration pas encore backfillé :
        // traité comme owner (comportement historique, aucune session cassée).
        // Tous les chemins de création posent role_org, et la migration
        // backfille l'existant — les « admin » sont donc toujours explicites.
        if (!$user || !$user->isHotelAdmin() || $user->role_org === 'admin') {
            return response()->json([
                'data'   => null,
                'errors' => [[
                    'code'    => 'ROLE_ORG_FORBIDDEN',
                    'message' => 'Action réservée au propriétaire du compte.',
                    'field'   => null,
                ]],
            ], 403);
        }

        return $next($request);
    }
}
