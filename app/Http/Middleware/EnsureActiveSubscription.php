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

        // Determine which entity holds the subscription
        if ($org) {
            $cacheKey = "org_subscription_active:{$org->id}";
            $isActive = Cache::remember($cacheKey, 60, fn() => $org->hasActiveSubscription());
            $sub      = $org->activeSubscription ?? $org->subscriptions()->latest()->first();
        } else {
            // True legacy: hotel not in any org — check hotel-level subscription
            $cacheKey = "hotel_subscription_active:{$hotel->id}";
            $isActive = Cache::remember($cacheKey, 60, fn() => $hotel->hasActiveSubscription());
            $sub      = $hotel->activeSubscription ?? $hotel->subscriptions()->latest()->first();
        }

        if (!$isActive) {
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
