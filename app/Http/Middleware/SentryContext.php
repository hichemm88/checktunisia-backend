<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * Attache le contexte de diagnostic aux événements Sentry.
 *
 * Ce qui est attaché : de quoi répondre à « où et pour qui ça a cassé ? » —
 * rôle, établissement, organisation, identifiant interne de l'opérateur.
 *
 * Ce qui n'est JAMAIS attaché : nom, email, numéro de document, date de
 * naissance — de l'opérateur comme du voyageur. Un identifiant interne suffit
 * à corréler des occurrences ; croiser une identité réelle avec une trace
 * d'erreur n'apporte rien au diagnostic et sort la donnée de son périmètre.
 *
 * Inerte si Sentry n'est pas configuré.
 */
class SentryContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sentry.dsn')) {
            return $next($request);
        }

        configureScope(function (Scope $scope) use ($request): void {
            $user = $request->user();

            if (! $user) {
                $scope->setTag('auth', 'guest');

                return;
            }

            // Identifiant opaque uniquement — permet de regrouper les
            // occurrences sans révéler qui que ce soit.
            $scope->setUser(['id' => (string) $user->getAuthIdentifier()]);

            $scope->setTag('role', (string) ($user->primary_role ?? 'unknown'));

            if ($user->organization_id) {
                $scope->setTag('organization_id', (string) $user->organization_id);
            }

            // Établissement actif résolu par ResolveTenant, qui le partage via
            // le conteneur (app()->instance('tenant', $hotel)) et non via les
            // attributs de requête.
            if (app()->bound('tenant') && ($hotel = app('tenant'))) {
                $scope->setTag('hotel_id', (string) $hotel->id);
            }
        });

        return $next($request);
    }
}
