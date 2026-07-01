<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hotel = app('tenant');
        $query = Room::where('hotel_id', $hotel->id);

        if ($request->filled('status')) $query->where('status', $request->status);

        $rooms = $query->orderBy('number')->get();

        return response()->json(['data' => $rooms->map(fn($r) => [
            'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor,
            'type' => $r->type, 'capacity' => $r->capacity, 'status' => $r->status,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $hotel = app('tenant');
        $validated = $request->validate([
            'number'   => ['required', 'string', 'max:20'],
            'floor'    => ['nullable', 'integer'],
            'type'     => ['string', 'in:standard,suite,apartment,dormitory,villa'],
            'capacity' => ['integer', 'min:1', 'max:20'],
        ]);

        // Ensure unique number per hotel
        if (Room::where('hotel_id', $hotel->id)->where('number', $validated['number'])->exists()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'Room number already exists.', 'field' => 'number']],
            ], 422);
        }

        $room = Room::create(array_merge($validated, ['hotel_id' => $hotel->id]));
        AuditLogger::log('room.created', $room, [], $room->toArray(), hotelId: $hotel->id);

        return response()->json(['data' => $room], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $hotel = app('tenant');
        $room  = Room::where('hotel_id', $hotel->id)->findOrFail($id);
        $old   = $room->toArray();

        $validated = $request->validate([
            'status'   => ['sometimes', 'in:available,occupied,maintenance,inactive'],
            'type'     => ['sometimes', 'in:standard,suite,apartment,dormitory,villa'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
        ]);

        $room->update($validated);
        AuditLogger::log('room.updated', $room, $old, $room->fresh()->toArray(), hotelId: $hotel->id);

        return response()->json(['data' => $room->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $hotel = app('tenant');
        $room  = Room::where('hotel_id', $hotel->id)->findOrFail($id);
        $room->delete();
        AuditLogger::log('room.deleted', $room, hotelId: $hotel->id);

        return response()->json(null, 204);
    }
}
