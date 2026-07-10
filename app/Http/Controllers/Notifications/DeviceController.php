<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registers/removes a device's Expo push token (§6.3). Any authenticated user may register
 * their device; only managers actually receive pushes, but keeping registration open is
 * harmless and simpler.
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'    => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
        ]);

        $device = DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id'      => $request->user()->id,
                'platform'     => $validated['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['data' => ['id' => $device->id]], 201);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        DeviceToken::where('token', $token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
