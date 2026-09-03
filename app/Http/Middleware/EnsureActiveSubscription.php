<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks that the current tenant (or its owning organization) has an active subscription.
 *
 * Resolution order:
 *  1. If the hotel belongs to an org → check the org's subscription.
 *  2. Otherwise → check the hotel's own subscription (legacy / backwards-compat).
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Hotel $hotel */
        $hotel = app('tenant');

        if (!$hotel) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'TENANT_NOT_FOUND', 'message' => 'Hotel not found.', 'field' => null]],
            ], 404);
        }

        /** @var Organization|null $org */
        $org = app('organization');

        // ResolveTenant only binds 'organization' when the user has organization_id set.
        // For legacy users (organization_id still null), fall back to the hotel's own org link.
        if (!$org && $hotel->organization_id) {
            $org = Organization::find($hotel->organization_id);
        }

        // Determine which entity holds the subscription. Legacy hotels not
        // attached to any org carry their own.
        $holder   = $org ?? $hotel;
        $cacheKey = ($org ? 'org' : 'hotel')."_subscription_active:{$holder->id}";
        $isActive = Cache::remember($cacheKey, 60, fn () => $holder->hasActiveSubscription());

        if (!$isActive) {
            /*
             * L'abonnement n'est chargé QUE sur le chemin d'échec.
             *
             * Il l'était auparavant à chaque passage, alors qu'il ne sert
             * qu'à choisir le libellé du refus ci-dessous. Ce middleware
             * s'exécute sur toutes les requêtes authentifiées d'un
             * établissement : c'était donc une requête SQL par appel d'API,
             * payée sur le cas nominal pour préparer un message que l'on
             * n'affiche presque jamais.
             *
             * Cela vidait aussi le cache de son intérêt : `$isActive` était mis
             * en cache 60 s, mais la requête qu'il évitait repartait juste en
             * dessous.
             */
            $sub = $holder->activeSubscription ?? $holder->subscriptions()->latest()->first();

            $code = match (true) {
                $sub?->isSuspended()    => 'SUBSCRIPTION_SUSPENDED',
                $sub?->isTrialExpired() => 'TRIAL_EXPIRED',
                default                 => 'SUBSCRIPTION_INACTIVE',
            };
            $message = match ($code) {
                'SUBSCRIPTION_SUSPENDED' => 'Abonnement suspendu. Contactez votre administrateur.',
                'TRIAL_EXPIRED'          => 'Votre essai gratuit est terminé. Passez à un abonnement payant pour continuer.',
                default                  => 'Aucun abonnement actif. Le check-in n\'est pas disponible.',
            };

            return response()->json([
                'data'   => null,
                'errors' => [['code' => $code, 'message' => $message, 'field' => null]],
            ], 403);
        }

        return $next($request);
    }
}
