<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * Get onboarding status for the current hotel.
     */
    public function status(Request $request): JsonResponse
    {
        $hotel = $request->user()->currentHotel();

        return response()->json([
            'data' => [
                'setup_completed'    => !is_null($hotel->setup_completed_at),
                'setup_completed_at' => $hotel->setup_completed_at,
            ],
        ]);
    }

    /**
     * Mark setup as complete.
     */
    public function complete(Request $request): JsonResponse
    {
        $hotel = $request->user()->currentHotel();

        if (is_null($hotel->setup_completed_at)) {
            $hotel->update(['setup_completed_at' => now()]);
        }

        return response()->json([
            'data' => ['setup_completed' => true, 'setup_completed_at' => $hotel->setup_completed_at],
        ]);
    }
}
