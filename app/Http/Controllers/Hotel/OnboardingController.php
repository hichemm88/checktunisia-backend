<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding status for hotel_admin.
 *
 * Works before any property has been created (new registration flow where
 * the property step is deferred to post-login onboarding).
 *
 * Routes are intentionally placed OUTSIDE the 'tenant' middleware group
 * so they are reachable even when the org has zero properties.
 */
class OnboardingController extends Controller
{
    /**
     * Return onboarding status for the user's active property.
     * Returns has_property: false when the org has no properties yet.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            return response()->json([
                'data' => [
                    'setup_completed'    => false,
                    'setup_completed_at' => null,
                    'has_property'       => false,
                ],
            ]);
        }

        $hotel = $this->resolveHotel($request, $org);

        if (!$hotel) {
            return response()->json([
                'data' => [
                    'setup_completed'    => false,
                    'setup_completed_at' => null,
                    'has_property'       => false,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'setup_completed'    => !is_null($hotel->setup_completed_at),
                'setup_completed_at' => $hotel->setup_completed_at,
                'has_property'       => true,
            ],
        ]);
    }

    /**
     * Mark the active property's setup as complete.
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        $hotel = $org ? $this->resolveHotel($request, $org) : null;

        // Legacy fallback
        if (!$hotel && app()->bound('tenant')) {
            $hotel = app('tenant');
        }

        if (!$hotel) {
            return response()->json([
                'errors' => [['code' => 'NO_PROPERTY', 'message' => 'Aucun établissement à configurer.']],
            ], 422);
        }

        if (is_null($hotel->setup_completed_at)) {
            $hotel->update(['setup_completed_at' => now()]);
        }

        return response()->json([
            'data' => [
                'setup_completed'    => true,
                'setup_completed_at' => $hotel->fresh()->setup_completed_at,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveHotel(Request $request, $org)
    {
        $requestedId = $request->header('X-Property-Id');

        if ($requestedId) {
            $hotel = $org->properties()->where('id', $requestedId)->first();
            if ($hotel) return $hotel;
        }

        return $org->properties()
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first()
            ?? $org->properties()->orderBy('created_at')->first();
    }
}
