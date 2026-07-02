<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\WatchlistHit;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistHitController extends Controller
{
    /**
     * GET /hotel/watchlist-hits
     * List unacknowledged watchlist hits for this hotel.
     * Returns minimal info only — no guest names, no watchlist details.
     */
    public function index(Request $request): JsonResponse
    {
        $hotel = app('tenant');

        $hits = WatchlistHit::with(['checkIn'])
            ->where('hotel_id', $hotel->id)
            ->whereNull('acknowledged_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $hits->map(fn ($h) => [
                'id'               => $h->id,
                'check_in_reference' => $h->checkIn?->reference,
                'check_in_date'    => $h->checkIn?->check_in_date,
                'room_number'      => $h->checkIn?->room?->number,
                'notified_at'      => $h->notified_hotel_at,
                // Deliberately no guest name, no watchlist details
            ]),
            'meta' => [
                'total' => $hits->count(),
            ],
        ]);
    }

    /**
     * POST /hotel/watchlist-hits/{id}/acknowledge
     * Hotel staff acknowledges they have contacted the authorities.
     */
    public function acknowledge(string $id, Request $request): JsonResponse
    {
        $hotel = app('tenant');

        $hit = WatchlistHit::where('id', $id)
            ->where('hotel_id', $hotel->id)
            ->firstOrFail();

        $hit->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
        ]);

        AuditLogger::log('watchlist.hit_acknowledged', $hit, hotelId: $hotel->id);

        return response()->json(['data' => ['acknowledged_at' => $hit->acknowledged_at]]);
    }
}
