<?php

namespace App\Providers;

use App\Models\Prospection\ProspectionAccessToken;
use App\Models\Prospection\ProspectionAction;
use App\Observers\Prospection\ProspectionActionObserver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Minishlink\WebPush\WebPush;

/**
 * Enregistrement du guard d'authentification du CRM de prospection (voir
 * config/auth.php, 'prospection' guard) et du client Web Push utilisé par
 * App\Services\Prospection\WebPushSender.
 */
class ProspectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Résolu paresseusement (singleton = construit à la première
        // utilisation, pas au boot) : un déploiement sans clés VAPID
        // encore posées ne doit pas empêcher TOUT le reste de l'app de
        // démarrer, seul l'envoi de push doit rester inerte.
        $this->app->singleton(WebPush::class, function () {
            $publicKey = config('webpush.public_key');
            $privateKey = config('webpush.private_key');

            $auth = ($publicKey && $privateKey) ? [
                'VAPID' => [
                    'subject' => config('webpush.subject'),
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ] : [];

            // Un 5ᵉ argument $logger : sans lui, la lib avertit par
            // trigger_error() (ex. "installez GMP/BCMath pour de meilleures
            // perfs") — un simple conseil, mais que le gestionnaire d'erreurs
            // de Laravel transforme en exception FATALE. Lui donner le logger
            // de l'app fait passer ces avis en écriture de log normale,
            // cohérent avec la promesse de WebPushSender : un push qui
            // tourne mal ne doit jamais faire échouer autre chose.
            return new WebPush($auth, logger: Log::getFacadeRoot());
        });
    }

    public function boot(): void
    {
        // Déclencheur "activité de l'équipe" (§ Notifications push) — voir
        // ProspectionActionObserver pour la sélection des actions notables.
        ProspectionAction::observe(ProspectionActionObserver::class);

        Auth::viaRequest('prospection-token', function (Request $request) {
            $plainTextToken = $request->bearerToken();

            if (!$plainTextToken) {
                return null;
            }

            $accessToken = ProspectionAccessToken::where('token_hash', hash('sha256', $plainTextToken))
                ->where('expires_at', '>', now())
                ->first();

            if (!$accessToken) {
                return null;
            }

            $user = $accessToken->user;

            if (!$user || !$user->active) {
                return null;
            }

            // Ne réécrit pas à chaque requête : un outil consulté en
            // continu pendant une tournée écrirait plusieurs fois par
            // minute pour une information dont personne ne lit la
            // précision à la seconde près.
            if (!$accessToken->last_used_at || $accessToken->last_used_at->lt(now()->subMinutes(5))) {
                $accessToken->forceFill(['last_used_at' => now()])->save();
            }

            $request->attributes->set('prospection_access_token', $accessToken);

            return $user;
        });
    }
}
