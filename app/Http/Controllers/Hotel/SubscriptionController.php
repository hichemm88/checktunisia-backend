<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the current subscription for the authenticated user's organisation.
 *
 * Route is outside the 'tenant' middleware group — subscription is org-level
 * and must be readable even before a first property has been created.
 */
class SubscriptionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            $sub = $org->activeSubscription()->with('plan')->first();
        } else {
            // Legacy fallback: user has no org yet — try hotel pivot
            $hotel = $user->hotel();
            $sub   = $hotel
                ? Subscription::where('hotel_id', $hotel->id)
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest('started_at')
                    ->first()
                : null;
        }

        if (!$sub) {
            return response()->json(['data' => ['status' => 'none']]);
        }

        return response()->json([
            'data' => [
                'id'             => $sub->id,
                'plan'           => $sub->plan,
                'status'         => $sub->status,
                'billing_cycle'  => $sub->billing_cycle,
                'started_at'     => $sub->started_at,
                'expires_at'     => $sub->expires_at,
                'auto_renew'     => $sub->auto_renew,
                'days_remaining' => $sub->days_remaining,
            ],
        ]);
    }
}
