<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization & multi-property management for hotel_admin.
 *
 * GET  /hotel/organization          → org info + all properties
 * PATCH /hotel/organization         → update org info
 * GET  /hotel/organization/properties → list all properties
 * POST /hotel/organization/properties → add a new property to the org
 */
class OrganizationController extends Controller
{
    /** Return the org info and its properties list. */
    public function show(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            return response()->json(['data' => null, 'errors' => [['code' => 'ORG_NOT_FOUND', 'message' => 'No organization linked to this account.']]], 404);
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
        /** @var \App\Models\User $user */
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
        /** @var \App\Models\User $user */
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            return response()->json(['data' => []]);
        }

        $properties = $org->properties()
            ->with('address')
            ->orderBy('created_at')
            ->get()
            ->map(fn(Hotel $h) => $this->formatProperty($h));

        return response()->json(['data' => $properties]);
    }

    /** Add a new property to the org. */
    public function addProperty(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $org  = $user->organization;

        if (!$org) {
            return response()->json(['data' => null, 'errors' => [['code' => 'ORG_NOT_FOUND', 'message' => 'No organization linked to this account.']]], 404);
        }

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'type'                => ['required', 'in:hotel,guesthouse,appartement,villa,riad,maison_hotes,hostel,resort,bungalow,rental'],
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
            $plan = $sub->plan;
            $newTotal = $org->totalRooms() + $validated['room_count'];
            if ($plan->max_rooms && $newTotal > $plan->max_rooms) {
                return response()->json([
                    'message' => 'Le total de chambres dépasse la limite de votre plan.',
                    'errors'  => ['room_count' => ["Votre plan {$plan->name} autorise au maximum {$plan->max_rooms} chambres au total."]],
                ], 422);
            }
        }

        $hotel = Hotel::create([
            'organization_id'     => $org->id,
            'name'                => $validated['name'],
            'type'                => $validated['type'],
            'room_count'          => $validated['room_count'],
            'stars'               => $validated['stars'] ?? null,
            'registration_number' => $validated['registration_number'] ?? null,
            'status'              => 'active',
            'created_by'          => $user->id,
        ]);

        HotelAddress::create([
            'hotel_id'    => $hotel->id,
            'line1'       => $validated['address']['line1'],
            'city'        => $validated['address']['city'],
            'governorate' => $validated['address']['governorate'],
            'postal_code' => $validated['address']['postal_code'] ?? null,
            'country'     => 'TN',
            'is_primary'  => true,
        ]);

        HotelContact::create([
            'hotel_id'   => $hotel->id,
            'type'       => 'email',
            'value'      => $org->contact_email,
            'is_primary' => true,
        ]);

        // Give hotel_admin access to new property via pivot
        $hotel->users()->attach($user->id, ['granted_at' => now()]);

        return response()->json([
            'data'    => $this->formatProperty($hotel->load('address')),
            'message' => 'Propriété ajoutée avec succès.',
        ], 201);
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
