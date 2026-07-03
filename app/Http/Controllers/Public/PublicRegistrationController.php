<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PublicRegistrationController extends Controller
{
    /**
     * Self-service registration.
     *
     * Creates:
     *   1. Organization (société ou particulier)
     *   2. First property under that org
     *   3. hotel_admin user linked to the org
     *   4. 30-day trial subscription at org level
     *
     * No payment required at sign-up.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Organization
            'entity_type'               => ['required', 'in:company,individual'],
            'org_name'                  => ['required', 'string', 'max:255'],
            'org_registration_number'   => ['nullable', 'string', 'max:100'],
            'org_phone'                 => ['nullable', 'string', 'max:30'],

            // First property
            'property_name'   => ['required', 'string', 'max:255'],
            'property_type'   => ['required', 'in:hotel,guesthouse,appartement,villa,riad,maison_hotes,hostel,resort,bungalow,rental'],
            'room_count'      => ['required', 'integer', 'min:1', 'max:9999'],
            'stars'           => ['nullable', 'integer', 'between:1,5'],
            'registration_number' => ['nullable', 'string', 'max:100'], // property's RC

            // Property address
            'address.line1'       => ['required', 'string', 'max:255'],
            'address.city'        => ['required', 'string', 'max:100'],
            'address.governorate' => ['required', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],

            // Admin account
            'first_name'  => ['required', 'string', 'max:100'],
            'last_name'   => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'password'    => ['required', 'confirmed', Password::min(12)
                ->mixedCase()->numbers()->symbols()],

            // Subscription plan
            'plan_slug'   => ['required', 'string', 'exists:subscription_plans,slug'],
        ]);

        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        // Validate room count fits the chosen plan
        if ($plan->max_rooms && $validated['room_count'] > $plan->max_rooms) {
            return response()->json([
                'message' => 'Le nombre de chambres dépasse la limite du plan sélectionné.',
                'errors'  => ['room_count' => ["Ce plan accepte au maximum {$plan->max_rooms} chambres."]],
            ], 422);
        }

        $result = DB::transaction(function () use ($validated, $plan) {

            // ── 1. Create Organization ────────────────────────────────────
            $org = Organization::create([
                'name'                => $validated['org_name'],
                'entity_type'         => $validated['entity_type'],
                'registration_number' => $validated['org_registration_number'] ?? null,
                'contact_email'       => $validated['email'],
                'contact_phone'       => $validated['org_phone'] ?? null,
                'address'             => $validated['address'],
                'status'              => 'pending',
            ]);

            // ── 2. Create first property ──────────────────────────────────
            $hotel = Hotel::create([
                'organization_id'     => $org->id,
                'name'                => $validated['property_name'],
                'type'                => $validated['property_type'],
                'room_count'          => $validated['room_count'],
                'stars'               => $validated['stars'] ?? null,
                'registration_number' => $validated['registration_number'] ?? null,
                'status'              => 'pending',
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
                'value'      => $validated['email'],
                'is_primary' => true,
            ]);

            // ── 3. Create admin user ──────────────────────────────────────
            $user = User::create([
                'organization_id'   => $org->id,
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'],
                'phone'             => $validated['phone'] ?? null,
                'password'          => Hash::make($validated['password']),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $user->assignRole('hotel_admin');

            // Link user ↔ property (pivot) for backwards-compat resolution
            $hotel->users()->attach($user->id, ['granted_at' => now()]);
            $hotel->update(['created_by' => $user->id]);
            $org->update(['status' => 'active']);

            // ── 4. Trial subscription at org level ────────────────────────
            $trialEnds = now()->addDays(30);
            $sub = Subscription::create([
                'organization_id' => $org->id,
                'hotel_id'        => $hotel->id, // kept for legacy compat
                'plan_id'         => $plan->id,
                'status'          => 'active',
                'billing_cycle'   => 'monthly',
                'started_at'      => now(),
                'expires_at'      => $trialEnds,
                'auto_renew'      => false,
                'created_by'      => $user->id,
                'metadata'        => ['trial' => true],
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'activated',
                'new_status'      => 'active',
                'performed_by'    => $user->id,
                'metadata'        => ['source' => 'self_registration', 'trial_days' => 30],
                'created_at'      => now(),
            ]);

            AuditLogger::log('organization.registered', $org, [], [
                'organization_id' => $org->id,
                'entity_type'     => $org->entity_type,
                'plan'            => $plan->slug,
                'trial'           => true,
                'properties'      => 1,
            ]);

            return compact('org', 'hotel', 'user', 'sub', 'trialEnds');
        });

        return response()->json([
            'data' => [
                'organization' => [
                    'id'   => $result['org']->id,
                    'name' => $result['org']->name,
                ],
                'property' => [
                    'id'   => $result['hotel']->id,
                    'name' => $result['hotel']->name,
                    'slug' => $result['hotel']->slug,
                ],
                'user' => [
                    'id'    => $result['user']->id,
                    'email' => $result['user']->email,
                    'name'  => $result['user']->first_name . ' ' . $result['user']->last_name,
                ],
                'trial_ends_at' => $result['trialEnds']->toIso8601String(),
                'plan'          => $plan->name,
            ],
            'message' => 'Inscription réussie ! Vous pouvez maintenant vous connecter.',
        ], 201);
    }
}
