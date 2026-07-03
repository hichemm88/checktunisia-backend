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

        // Determine which entity holds the subscription
        if ($org) {
            $cacheKey = "org_subscription_active:{$org->id}";
            $isActive = Cache::remember($cacheKey, 300, fn() => $org->hasActiveSubscription());
            $sub      = $org->activeSubscription ?? $org->subscriptions()->latest()->first();
        } else {
            // Legacy: subscription on hotel itself
            $cacheKey = "hotel_subscription_active:{$hotel->id}";
            $isActive = Cache::remember($cacheKey, 300, fn() => $hotel->hasActiveSubscription());
            $sub      = $hotel->activeSubscription ?? $hotel->subscriptions()->latest()->first();
        }

        if (!$isActive) {
            $code    = $sub?->isSuspended() ? 'SUBSCRIPTION_SUSPENDED' : 'SUBSCRIPTION_INACTIVE';
            $message = $sub?->isSuspended()
                ? 'Abonnement suspendu. Contactez votre administrateur.'
                : 'Aucun abonnement actif. Le check-in n\'est pas disponible.';

            return response()->json([
                'data'   => null,
                'errors' => [['code' => $code, 'message' => $message, 'field' => null]],
            ], 403);
        }

        return $next($request);
    }
}
