<?php

namespace App\Services\Observability;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Filtrage des événements avant envoi à Sentry.
 *
 * Cette logique vivait dans une CLOSURE au sein de config/sentry.php. C'était
 * un défaut bloquant : `php artisan config:cache` sérialise la configuration
 * avec var_export, qui ne sait pas exporter une closure
 * (« Call to undefined method Closure::__set_state() »). Comme
 * docker/start.sh tourne avec `set -e` et exécute config:cache au démarrage,
 * le conteneur de production n'aurait jamais démarré.
 *
 * Une méthode statique référencée en tableau [classe, méthode] est, elle,
 * parfaitement sérialisable — et testable directement, ce qu'une closure de
 * configuration n'était pas.
 */
class SentryScrubber
{
    /**
     * Deux rôles : retirer tout ce qui pourrait identifier un voyageur, et ne
     * pas polluer le suivi avec des exceptions qui ne sont pas des défauts.
     */
    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        $exception = $hint?->exception;

        // Les erreurs « attendues » ne sont pas des incidents : une validation
        // refusée ou une limite de débit atteinte est le système qui
        // fonctionne. Les laisser passer noierait les vrais défauts.
        if ($exception instanceof \Illuminate\Validation\ValidationException
            || $exception instanceof \Illuminate\Auth\AuthenticationException
            || $exception instanceof \Illuminate\Auth\Access\AuthorizationException
            || $exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
            || $exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException
            || $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
        ) {
            return null;
        }

        $request = $event->getRequest();

        // Ceinture et bretelles : même si send_default_pii repassait à true un
        // jour par erreur, ces clés ne partiraient pas.
        unset($request['data'], $request['cookies'], $request['env']);

        // La query string peut contenir les critères de recherche autorité
        // (nom, numéro de document). On garde l'URL sans ses paramètres.
        unset($request['query_string']);

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = strtok($request['url'], '?');
        }

        $event->setRequest($request);

        return $event;
    }
}
