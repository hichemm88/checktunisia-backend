<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
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

    /**
     * Room availability for a stay date range — shared occupancy source for the
     * check-in room selector (web + mobile). A stay occupies the nights
     * [check_in_date, departure), so a room whose occupant leaves on `from` is
     * available that day (flagged `departing_same_day` for the amber indicator).
     */
    public function availability(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after:from'],
        ]);
        $hotel = app('tenant');

        $rooms = Room::where('hotel_id', $hotel->id)->orderBy('number')->get();

        // Stays overlapping the requested nights [from, to)
        $conflicts = CheckIn::with('primaryGuest')
            ->where('hotel_id', $hotel->id)
            ->whereNotNull('room_id')
            ->whereIn('status', ['draft', 'active'])
            ->where('check_in_date', '<', $v['to'])
            ->whereRaw('COALESCE(actual_check_out_date, expected_check_out_date) > ?', [$v['from']])
            ->get()
            ->groupBy('room_id');

        // Active stays whose departure falls exactly on `from` — room frees that day
        $departingSameDay = CheckIn::where('hotel_id', $hotel->id)
            ->whereNotNull('room_id')
            ->where('status', 'active')
            ->whereNull('actual_check_out_date')
            ->whereDate('expected_check_out_date', $v['from'])
            ->pluck('room_id')
            ->flip();

        return response()->json(['data' => $rooms->map(function ($r) use ($conflicts, $departingSameDay) {
            $conflict = $conflicts->get($r->id)?->first();
            $state = !in_array($r->status, ['available', 'occupied'], true) ? 'unavailable'
                : ($conflict ? 'occupied' : 'free');

            $guest = $conflict?->primaryGuest->first();

            return [
                'id'                 => $r->id,
                'number'             => $r->number,
                'floor'              => $r->floor,
                'type'               => $r->type,
                'capacity'           => $r->capacity,
                'status'             => $r->status,
                'state'              => $state,
                'departing_same_day' => $state === 'free' && isset($departingSameDay[$r->id]),
                'conflict'           => $conflict ? [
                    'reference'      => $conflict->reference,
                    'guest_name'     => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                    'check_in_date'  => $conflict->check_in_date?->toDateString(),
                    'departure_date' => ($conflict->actual_check_out_date ?? $conflict->expected_check_out_date)?->toDateString(),
                ] : null,
            ];
        })]);
    }

    // Valid room types shared between store and update
    private const ROOM_TYPES = [
        'single', 'double', 'twin', 'triple', 'quadruple',
        'suite', 'junior_suite', 'apartment', 'studio',
        'family', 'villa', 'dormitory', 'standard',
    ];

    public function store(Request $request): JsonResponse
    {
        $hotel = app('tenant');
        $validated = $request->validate([
            'number'   => ['required', 'string', 'max:20'],
            'floor'    => ['nullable', 'integer', 'min:-5', 'max:200'],
            'type'     => ['string', 'in:' . implode(',', self::ROOM_TYPES)],
            'capacity' => ['integer', 'min:1', 'max:20'],
        ]);

        // Ensure unique number per hotel
        if (Room::where('hotel_id', $hotel->id)->where('number', $validated['number'])->exists()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'Ce numéro de chambre existe déjà.', 'field' => 'number']],
            ], 422);
        }

        $room = Room::create(array_merge($validated, [
            'hotel_id' => $hotel->id,
            'status'   => 'available',
        ]));
        AuditLogger::log('room.created', $room, [], $room->toArray(), hotelId: $hotel->id);

        return response()->json(['data' => $room], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $hotel = app('tenant');
        $room  = Room::where('hotel_id', $hotel->id)->findOrFail($id);
        $old   = $room->toArray();

        $validated = $request->validate([
            'number'   => ['sometimes', 'string', 'max:20'],
            'floor'    => ['sometimes', 'nullable', 'integer', 'min:-5', 'max:200'],
            'type'     => ['sometimes', 'in:' . implode(',', self::ROOM_TYPES)],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'status'   => ['sometimes', 'in:available,occupied,maintenance,inactive'],
        ]);

        // Check number uniqueness on rename
        if (isset($validated['number']) && $validated['number'] !== $room->number) {
            if (Room::where('hotel_id', $hotel->id)->where('number', $validated['number'])->exists()) {
                return response()->json([
                    'data'   => null,
                    'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'Ce numéro de chambre existe déjà.', 'field' => 'number']],
                ], 422);
            }
        }

        $room->update($validated);
        AuditLogger::log('room.updated', $room, $old, $room->fresh()->toArray(), hotelId: $hotel->id);

        return response()->json(['data' => $room->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $hotel = app('tenant');
        $room  = Room::where('hotel_id', $hotel->id)->findOrFail($id);
        $old   = $room->toArray();
        $room->delete();
        AuditLogger::log('room.deleted', $room, $old, [], hotelId: $hotel->id);

        return response()->json(null, 204);
    }
}
