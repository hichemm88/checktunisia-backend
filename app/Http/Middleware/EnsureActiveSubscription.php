<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

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

        // Cache subscription status for 5 minutes to avoid DB hit on every request
        $cacheKey = "hotel_subscription_active:{$hotel->id}";
        $isActive  = Cache::remember($cacheKey, 300, fn() => $hotel->hasActiveSubscription());

        if (!$isActive) {
            $sub = $hotel->activeSubscription ?? $hotel->subscriptions()->latest()->first();

            $code    = $sub?->isSuspended() ? 'SUBSCRIPTION_SUSPENDED' : 'SUBSCRIPTION_INACTIVE';
            $message = $sub?->isSuspended()
                ? 'Subscription suspended. Contact your administrator.'
                : 'No active subscription. Check-in is not available.';

            return response()->json([
                'data'   => null,
                'errors' => [['code' => $code, 'message' => $message, 'field' => null]],
            ], 403);
        }

        return $next($request);
    }
}
