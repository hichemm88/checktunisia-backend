<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HotelProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $hotel = app('tenant')->load(['address', 'contacts', 'organization']);

        $address = $hotel->address;
        $phone   = $hotel->contacts()->where('type', 'phone')->where('is_primary', true)->first();
        $email   = $hotel->contacts()->where('type', 'email')->where('is_primary', true)->first();
        $website = $hotel->contacts()->where('type', 'website')->where('is_primary', true)->first();
        $org     = $hotel->organization;

        return response()->json([
            'data' => [
                'id'                  => $hotel->id,
                'name'                => $hotel->name,
                'type'                => $hotel->type,
                'stars'               => $hotel->stars,
                'registration_number' => $hotel->registration_number,
                'status'              => $hotel->status,
                'address'             => $address ? [
                    'line1'       => $address->line1,
                    'line2'       => $address->line2,
                    'city'        => $address->city,
                    'governorate' => $address->governorate,
                    'postal_code' => $address->postal_code,
                    'latitude'    => $address->latitude,
                    'longitude'   => $address->longitude,
                ] : null,
                'phone'   => $phone?->value,
                'email'   => $email?->value,
                'website' => $website?->value,
                // Owning company/individual — printed alongside the property on official documents.
                'organization' => $org ? [
                    'name'                => $org->name,
                    'entity_type'         => $org->entity_type,
                    'registration_number' => $org->registration_number,
                    'contact_email'       => $org->contact_email,
                    'contact_phone'       => $org->contact_phone,
                    'address'             => $org->address,
                ] : null,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hotel = app('tenant');

        $validated = $request->validate([
            'name'  => ['sometimes', 'string', 'max:255'],
            'type'  => ['sometimes', 'nullable', 'string', 'in:hotel,residence,hostel,guesthouse,villa,riad'],
            'stars' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            // Address
            'address'              => ['sometimes', 'array'],
            'address.line1'        => ['sometimes', 'string', 'max:255'],
            'address.line2'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'address.city'         => ['sometimes', 'string', 'max:100'],
            'address.governorate'  => ['sometimes', 'string', 'max:100'],
            'address.postal_code'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'address.latitude'     => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'address.longitude'    => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            // Contacts
            'phone'   => ['sometimes', 'nullable', 'string', 'max:30'],
            'email'   => ['sometimes', 'nullable', 'email', 'max:255'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Update hotel core fields
        $hotelFields = array_intersect_key($validated, array_flip(['name', 'type', 'stars']));
        if (!empty($hotelFields)) {
            $hotel->update($hotelFields);
        }

        // Upsert primary address
        if (isset($validated['address'])) {
            $hotel->addresses()->updateOrCreate(
                ['is_primary' => true],
                array_merge($validated['address'], [
                    'is_primary'   => true,
                    'country_code' => 'TN',
                ])
            );
        }

        // Upsert contacts
        foreach (['phone', 'email', 'website'] as $type) {
            if (array_key_exists($type, $validated)) {
                if ($validated[$type]) {
                    $hotel->contacts()->updateOrCreate(
                        ['type' => $type, 'is_primary' => true],
                        ['value' => $validated[$type], 'is_primary' => true]
                    );
                } else {
                    $hotel->contacts()->where('type', $type)->where('is_primary', true)->delete();
                }
            }
        }

        return $this->show();
    }
}
