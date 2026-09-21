<?php

namespace App\Providers;

use App\Models\Prospection\ProspectionAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Enregistrement du guard d'authentification du CRM de prospection. Voir
 * config/auth.php ('prospection' guard) et la migration
 * create_prospection_access_tokens_table pour le pourquoi d'un mécanisme
 * dédié plutôt que Laravel Sanctum.
 */
class ProspectionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::viaRequest('prospection-token', function (Request $request) {
            $plainTextToken = $request->bearerToken();

            if (! $plainTextToken) {
                return null;
            }

            $accessToken = ProspectionAccessToken::where('token_hash', hash('sha256', $plainTextToken))
                ->where('expires_at', '>', now())
                ->first();

            if (! $accessToken) {
                return null;
            }

            $user = $accessToken->user;

            if (! $user || ! $user->active) {
                return null;
            }

            // Ne réécrit pas à chaque requête : un outil consulté en
            // continu pendant une tournée écrirait plusieurs fois par
            // minute pour une information dont personne ne lit la
            // précision à la seconde près.
            if (! $accessToken->last_used_at || $accessToken->last_used_at->lt(now()->subMinutes(5))) {
                $accessToken->forceFill(['last_used_at' => now()])->save();
            }

            $request->attributes->set('prospection_access_token', $accessToken);

            return $user;
        });
    }
}
