<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
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
     *   2. hotel_admin user linked to the org
     *   3. 7-day trial subscription at org level
     *
     * Properties are added post-login via the onboarding wizard.
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

            // Admin account
            'first_name'  => ['required', 'string', 'max:100'],
            'last_name'   => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'       => ['nullable', 'string', 'max:30'],
            'password'    => ['required', 'confirmed', Password::min(12)
                ->mixedCase()->numbers()->symbols()],

            // Subscription plan
            'plan_slug'     => ['required', 'string', 'exists:subscription_plans,slug'],
            'billing_cycle' => ['sometimes', 'in:monthly,yearly'],

            // Langue de communication (emails) — la langue de l'interface au moment
            // de l'inscription. Repli francais si absente ou non supportee.
            'locale'        => ['sometimes', 'in:fr,en,ar'],
        ]);
        $locale = $validated['locale'] ?? 'fr';

        $plan = SubscriptionPlan::where('slug', $validated['plan_slug'])
            ->where('is_active', true)
            ->firstOrFail();

        $result = DB::transaction(function () use ($validated, $plan, $locale) {

            // ── 1. Create Organization ────────────────────────────────────
            $org = Organization::create([
                'name'                => $validated['org_name'],
                'entity_type'         => $validated['entity_type'],
                'registration_number' => $validated['org_registration_number'] ?? null,
                'contact_email'       => $validated['email'],
                'contact_phone'       => $validated['org_phone'] ?? null,
                'status'              => 'active',
                'locale'              => $locale,
            ]);

            // ── 2. Create admin user ──────────────────────────────────────
            $user = User::create([
                'organization_id'   => $org->id,
                'first_name'        => $validated['first_name'],
                'last_name'         => $validated['last_name'],
                'email'             => $validated['email'],
                'phone'             => $validated['phone'] ?? null,
                'password'          => Hash::make($validated['password']),
                'status'            => 'active',
                'locale'            => $locale,
                'email_verified_at' => now(),
            ]);
            $user->assignRole('hotel_admin');

            // ── 3. Trial subscription at org level (no property yet) ──────
            $trialEnds = now()->addDays(7);
            $sub = Subscription::create([
                'organization_id' => $org->id,
                // hotel_id is intentionally omitted (nullable since migration 2026_07_03_200001)
                // it will be back-filled when the first property is created in onboarding
                'plan_id'         => $plan->id,
                'status'          => 'trial',
                // The cycle chosen at sign-up (yearly = one month free) — used when the trial converts to paid.
                'billing_cycle'   => $validated['billing_cycle'] ?? 'monthly',
                'started_at'      => now(),
                'expires_at'      => $trialEnds,
                'auto_renew'      => false,
                'created_by'      => $user->id,
                'metadata'        => ['trial' => true],
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'activated',
                'new_status'      => 'trial',
                'performed_by'    => $user->id,
                'metadata'        => ['source' => 'self_registration', 'trial_days' => 7],
                'created_at'      => now(),
            ]);

            AuditLogger::log('organization.registered', $org, [], [
                'organization_id' => $org->id,
                'entity_type'     => $org->entity_type,
                'plan'            => $plan->slug,
                'trial'           => true,
                'properties'      => 0, // property added during onboarding
            ]);

            return compact('org', 'user', 'sub', 'trialEnds');
        });

        return response()->json([
            'data' => [
                'organization' => [
                    'id'   => $result['org']->id,
                    'name' => $result['org']->name,
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
