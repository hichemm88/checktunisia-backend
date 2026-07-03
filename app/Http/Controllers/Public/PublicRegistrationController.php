<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
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
     * Self-service hotel registration.
     *
     * Creates the hotel, the hotel_admin account, and a 30-day trial subscription
     * for the chosen plan. No payment required at this stage — the admin will
     * receive an invoice and initiate payment via the hotel portal.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Hotel info
            'hotel_name'          => ['required', 'string', 'max:255'],
            'hotel_type'          => ['required', 'in:hotel,guesthouse,rental,hostel,resort'],
            'room_count'          => ['required', 'integer', 'min:1', 'max:9999'],
            'stars'               => ['nullable', 'integer', 'between:1,5'],
            'registration_number' => ['nullable', 'string', 'max:100'],

            // Address
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

            // Subscription
            'plan_slug'   => ['required', 'string', 'exists:subscription_plans,slug'],
        ]);

        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        // Validate room count fits the plan
        if ($plan->max_rooms && $validated['room_count'] > $plan->max_rooms) {
            return response()->json([
                'message' => 'Le nombre de chambres dépasse la limite du plan sélectionné.',
                'errors'  => ['room_count' => ["Ce plan accepte au maximum {$plan->max_rooms} chambres."]],
            ], 422);
        }

        $result = DB::transaction(function () use ($validated, $plan) {
            // 1. Create hotel (pending until first payment)
            $hotel = Hotel::create([
                'name'                => $validated['hotel_name'],
                'type'                => $validated['hotel_type'],
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

            // 2. Create hotel admin user
            $user = User::create([
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'],
                'phone'             => $validated['phone'] ?? null,
                'password'          => Hash::make($validated['password']),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $user->assignRole('hotel_admin');

            // Link user ↔ hotel
            $hotel->users()->attach($user->id, ['granted_at' => now()]);
            $hotel->update(['created_by' => $user->id]);

            // 3. Create 30-day trial subscription
            $trialEnds = now()->addDays(30);
            $sub = Subscription::create([
                'hotel_id'      => $hotel->id,
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'billing_cycle' => 'monthly',
                'started_at'    => now(),
                'expires_at'    => $trialEnds,
                'auto_renew'    => false,
                'created_by'    => $user->id,
                'metadata'      => ['trial' => true],
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'activated',
                'new_status'      => 'active',
                'performed_by'    => $user->id,
                'metadata'        => ['source' => 'self_registration', 'trial_days' => 30],
                'created_at'      => now(),
            ]);

            AuditLogger::log('hotel.registered', $hotel, [], [
                'hotel_id' => $hotel->id,
                'plan'     => $plan->slug,
                'trial'    => true,
            ]);

            return compact('hotel', 'user', 'sub', 'trialEnds');
        });

        return response()->json([
            'data' => [
                'hotel'      => [
                    'id'   => $result['hotel']->id,
                    'name' => $result['hotel']->name,
                    'slug' => $result['hotel']->slug,
                ],
                'user'       => [
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
