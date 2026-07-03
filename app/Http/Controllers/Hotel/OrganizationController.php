<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organization & multi-property management for hotel_admin.
 *
 * GET    /hotel/organization                              → org info + all properties
 * PATCH  /hotel/organization                             → update org info
 * GET    /hotel/organization/properties                  → list all properties
 * POST   /hotel/organization/properties                  → add a new property
 * PATCH  /hotel/organization/properties/{id}             → update a property
 * GET    /hotel/organization/properties/{id}/rooms       → list rooms for a property
 * POST   /hotel/organization/properties/{id}/rooms       → add room to a property
 * PATCH  /hotel/organization/properties/{id}/rooms/{rid} → update a room
 * DELETE /hotel/organization/properties/{id}/rooms/{rid} → delete a room
 */
class OrganizationController extends Controller
{
    // ── Org endpoints ──────────────────────────────────────────────────────────

    /** Return org info and its properties list. Falls back to tenant hotel for legacy users. */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            // Legacy fallback: no organization_id on user yet
            $hotel = app('tenant');
            if (!$hotel) {
                return response()->json(['data' => null], 404);
            }

            // If the hotel already belongs to an org but the user wasn't linked, fix the gap silently
            if ($hotel->organization_id) {
                $org = Organization::find($hotel->organization_id);
                if ($org) {
                    $user->update(['organization_id' => $org->id]);
                    // Fall through to the org branch below
                }
            }

            if (!$org) {
                // True legacy: hotel not in any org — return synthetic single-property response
                $hotel->load('address');

                return response()->json([
                    'data' => [
                        'id'                  => null,
                        'name'                => $hotel->name,
                        'entity_type'         => 'company',
                        'registration_number' => $hotel->registration_number ?? null,
                        'contact_email'       => $user->email,
                        'contact_phone'       => null,
                        'address'             => [],
                        'status'              => $hotel->status,
                        'properties'          => [$this->formatProperty($hotel)],
                        'total_rooms'         => $hotel->room_count ?? 0,
                    ],
                ]);
            }
        }

        $properties = $org->properties()
            ->with('address')
            ->orderBy('created_at')
            ->get()
            ->map(fn(Hotel $h) => $this->formatProperty($h));

        return response()->json([
            'data' => [
                'id'                  => $org->id,
                'name'                => $org->name,
                'entity_type'         => $org->entity_type,
                'registration_number' => $org->registration_number,
                'contact_email'       => $org->contact_email,
                'contact_phone'       => $org->contact_phone,
                'address'             => $org->address,
                'status'              => $org->status,
                'properties'          => $properties,
                'total_rooms'         => $org->totalRooms(),
            ],
        ]);
    }

    /** Update org-level info (name, registration number, phone). */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            return response()->json(['data' => null], 404);
        }

        $validated = $request->validate([
            'name'                => ['sometimes', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_phone'       => ['sometimes', 'nullable', 'string', 'max:30'],
            'address.line1'       => ['sometimes', 'string', 'max:255'],
            'address.city'        => ['sometimes', 'string', 'max:100'],
            'address.governorate' => ['sometimes', 'string', 'max:100'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        if (isset($validated['address'])) {
            $validated['address'] = array_merge($org->address ?? [], $validated['address']);
        }

        $org->update($validated);

        return response()->json(['data' => ['message' => 'Organisation mise à jour.']]);
    }

    /** List all properties under the org. */
    public function properties(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            $hotel = app('tenant');
            return response()->json([
                'data' => $hotel ? [$this->formatProperty($hotel->load('address'))] : [],
            ]);
        }

        $properties = $org->properties()
            ->with('address')
            ->orderBy('created_at')
            ->get()
            ->map(fn(Hotel $h) => $this->formatProperty($h));

        return response()->json(['data' => $properties]);
    }

    /** Add a new property to the org. Auto-creates org for legacy users. */
    public function addProperty(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            // Auto-migrate legacy hotel_admin (no organization_id yet) to the org architecture.
            // NOTE: This route is NOT behind the tenant middleware, so app('tenant') is NOT
            // bound. We use $user->hotel() (pivot-based lookup) instead.
            $hotel = $user->hotel();
            if (!$hotel) {
                return response()->json([
                    'errors' => [['code' => 'ORG_NOT_FOUND', 'message' => 'Aucune organisation liée à ce compte.']],
                ], 404);
            }

            DB::transaction(function () use ($user, $hotel, &$org) {
                $org = Organization::create([
                    'name'          => $hotel->name,
                    'entity_type'   => 'company',
                    'contact_email' => $user->email,
                    'status'        => 'active',
                ]);
                $hotel->update(['organization_id' => $org->id]);
                $user->update(['organization_id'  => $org->id]);
                Subscription::where('hotel_id', $hotel->id)
                    ->whereNull('organization_id')
                    ->update(['organization_id' => $org->id]);
            });
        }

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'type'                => ['required', 'in:hotel,guesthouse,appartement,villa,riad,maison_hotes,hostel,resort,bungalow,rental,residence'],
            'room_count'          => ['required', 'integer', 'min:1', 'max:9999'],
            'stars'               => ['nullable', 'integer', 'between:1,5'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address.line1'       => ['required', 'string', 'max:255'],
            'address.city'        => ['required', 'string', 'max:100'],
            'address.governorate' => ['required', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        // Check plan limits on total rooms
        $sub = $org->activeSubscription;
        if ($sub) {
            $plan     = $sub->plan;
            $newTotal = $org->totalRooms() + $validated['room_count'];
            if ($plan->max_rooms && $newTotal > $plan->max_rooms) {
                return response()->json([
                    'message' => 'Le total de chambres dépasse la limite de votre plan.',
                    'errors'  => ['room_count' => ["Votre plan {$plan->name} autorise au maximum {$plan->max_rooms} chambres au total."]],
                ], 422);
            }
        }

        $property = Hotel::create([
            'organization_id'     => $org->id,
            'name'                => $validated['name'],
            'type'                => $validated['type'],
            'room_count'          => $validated['room_count'],
            'stars'               => $validated['stars'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'status'              => 'active',
            'created_by'          => $user->id,
            // Properties added via the multi-property UI are already "set up" — no onboarding wizard needed
            'setup_completed_at'  => now(),
        ]);

        HotelAddress::create([
            'hotel_id'    => $property->id,
            'line1'       => $validated['address']['line1'],
            'city'        => $validated['address']['city'],
            'governorate' => $validated['address']['governorate'],
            'postal_code' => $validated['address']['postal_code'] ?? null,
            'country'     => 'TN',
            'is_primary'  => true,
        ]);

        HotelContact::create([
            'hotel_id'   => $property->id,
            'type'       => 'email',
            'value'      => $org->contact_email,
            'is_primary' => true,
        ]);

        // Give hotel_admin access to new property via pivot
        $property->users()->attach($user->id, ['granted_at' => now()]);

        return response()->json([
            'data'    => $this->formatProperty($property->load('address')),
            'message' => 'Propriété ajoutée avec succès.',
        ], 201);
    }

    /** Update a property's details. */
    public function updateProperty(Request $request, string $propertyId): JsonResponse
    {
        $property = $this->resolveProperty($request, $propertyId);
        if ($property instanceof JsonResponse) return $property;

        $validated = $request->validate([
            'name'                => ['sometimes', 'string', 'max:255'],
            'type'                => ['sometimes', 'in:hotel,guesthouse,appartement,villa,riad,maison_hotes,hostel,resort,bungalow,rental,residence'],
            'room_count'          => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'stars'               => ['nullable', 'integer', 'between:1,5'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address.line1'       => ['sometimes', 'string', 'max:255'],
            'address.city'        => ['sometimes', 'string', 'max:100'],
            'address.governorate' => ['sometimes', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        $addressData = $validated['address'] ?? null;
        unset($validated['address']);

        $property->update($validated);

        if ($addressData) {
            $addr = $property->address;
            if ($addr) {
                $addr->update($addressData);
            } else {
                HotelAddress::create(array_merge(
                    $addressData,
                    ['hotel_id' => $property->id, 'country' => 'TN', 'is_primary' => true]
                ));
            }
        }

        return response()->json([
            'data'    => $this->formatProperty($property->load('address')),
            'message' => 'Propriété mise à jour.',
        ]);
    }

    // ── Per-property room endpoints ────────────────────────────────────────────

    /** List rooms for a specific property. */
    public function propertyRooms(Request $request, string $propertyId): JsonResponse
    {
        $property = $this->resolveProperty($request, $propertyId);
        if ($property instanceof JsonResponse) return $property;

        $rooms = $property->rooms()
            ->orderBy('floor')
            ->orderBy('number')
            ->get(['id', 'hotel_id', 'number', 'floor', 'type', 'capacity', 'status']);

        return response()->json(['data' => $rooms]);
    }

    /** Add a room to a specific property. */
    public function addPropertyRoom(Request $request, string $propertyId): JsonResponse
    {
        $property = $this->resolveProperty($request, $propertyId);
        if ($property instanceof JsonResponse) return $property;

        $validated = $request->validate([
            'number'   => ['required', 'string', 'max:20'],
            'floor'    => ['nullable', 'integer'],
            'type'     => ['required', 'string', 'in:single,double,twin,triple,quadruple,suite,junior_suite,apartment,studio,family,villa,dormitory,standard'],
            'capacity' => ['required', 'integer', 'min:1', 'max:20'],
            'status'   => ['sometimes', 'in:available,occupied,maintenance,inactive'],
        ]);

        $room = $property->rooms()->create(array_merge(
            $validated,
            ['status' => $validated['status'] ?? 'available']
        ));

        return response()->json(['data' => $room], 201);
    }

    /** Update a room within a specific property. */
    public function updatePropertyRoom(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $property = $this->resolveProperty($request, $propertyId);
        if ($property instanceof JsonResponse) return $property;

        $room = Room::where('id', $roomId)->where('hotel_id', $property->id)->first();
        if (!$room) {
            return response()->json(['errors' => [['code' => 'ROOM_NOT_FOUND']]], 404);
        }

        $validated = $request->validate([
            'number'   => ['sometimes', 'string', 'max:20'],
            'floor'    => ['nullable', 'integer'],
            'type'     => ['sometimes', 'string', 'in:single,double,twin,triple,quadruple,suite,junior_suite,apartment,studio,family,villa,dormitory,standard'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'status'   => ['sometimes', 'in:available,occupied,maintenance,inactive'],
        ]);

        $room->update($validated);
        return response()->json(['data' => $room]);
    }

    /** Delete a room within a specific property. */
    public function deletePropertyRoom(Request $request, string $propertyId, string $roomId): JsonResponse
    {
        $property = $this->resolveProperty($request, $propertyId);
        if ($property instanceof JsonResponse) return $property;

        $room = Room::where('id', $roomId)->where('hotel_id', $property->id)->first();
        if (!$room) {
            return response()->json(['errors' => [['code' => 'ROOM_NOT_FOUND']]], 404);
        }

        $room->delete();
        return response()->json(null, 204);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Resolve a property by ID, ensuring it belongs to the user's org.
     * Falls back to pivot check for legacy users without an org.
     */
    private function resolveProperty(Request $request, string $propertyId): Hotel|JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            $hotel = Hotel::where('id', $propertyId)
                ->where('organization_id', $org->id)
                ->first();
        } else {
            // Legacy: validate via pivot table
            $hotel = $user->hotels()->where('hotels.id', $propertyId)->first();
        }

        if (!$hotel) {
            return response()->json([
                'errors' => [['code' => 'PROPERTY_NOT_FOUND', 'message' => 'Propriété introuvable ou accès refusé.']],
            ], 404);
        }

        return $hotel;
    }

    private function formatProperty(Hotel $h): array
    {
        return [
            'id'                  => $h->id,
            'name'                => $h->name,
            'type'                => $h->type,
            'room_count'          => $h->room_count,
            'stars'               => $h->stars,
            'status'              => $h->status,
            'registration_number' => $h->registration_number,
            'address'             => $h->address ? [
                'line1'       => $h->address->line1,
                'city'        => $h->address->city,
                'governorate' => $h->address->governorate,
            ] : null,
        ];
    }
}
