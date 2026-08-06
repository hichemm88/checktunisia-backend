<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Organization\RoleOrgMigrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding status for hotel_admin.
 *
 * Works before any property has been created (new registration flow where
 * the property step is deferred to post-login onboarding).
 *
 * Routes are intentionally placed OUTSIDE the 'tenant' middleware group
 * so they are reachable even when the org has zero properties.
 */
class OnboardingController extends Controller
{
    /**
     * Return onboarding status, scoped to the ORGANIZATION — never to the user.
     *
     * The post-login gate keys on establishments_count + role_org:
     *   count >= 1            → dashboard (whatever the role)
     *   count == 0 && owner   → onboarding
     *   count == 0 && admin   → "Configuration en attente" screen
     *
     * Root-cause fix: a second hotel_admin used to reach this endpoint with a
     * null organization_id (the invitation flow only wrote the user_hotels
     * pivot), so the org lookup failed and the front redirected them to
     * onboarding even though the org's properties already existed.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $this->resolveOrganization($user);

        if ($org && $user->isHotelAdmin() && is_null($user->role_org)) {
            // Sessions créées avant la migration role_org : attribution paresseuse
            // (même règle idempotente que la migration/commande).
            RoleOrgMigrator::assignForOrg($org);
            $user->refresh();
        }

        if (!$org) {
            // Vrai legacy : ni organization_id ni propriété rattachée à une org.
            $legacyHotel = $user->hotel();

            return response()->json([
                'data' => [
                    'setup_completed'      => !is_null($legacyHotel?->setup_completed_at),
                    'setup_completed_at'   => $legacyHotel?->setup_completed_at,
                    'has_property'         => (bool) $legacyHotel,
                    'establishments_count' => $legacyHotel ? 1 : 0,
                    'role_org'             => $user->role_org,
                ],
            ]);
        }

        $count = $org->properties()->count();
        $hotel = $count > 0 ? $this->resolveHotel($request, $org) : null;

        return response()->json([
            'data' => [
                'setup_completed'      => !is_null($hotel?->setup_completed_at),
                'setup_completed_at'   => $hotel?->setup_completed_at,
                'has_property'         => $count > 0,
                'establishments_count' => $count,
                'role_org'             => $user->role_org,
            ],
        ]);
    }

    /**
     * Mark the active property's setup as complete.
     */
    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        $hotel = $org ? $this->resolveHotel($request, $org) : null;

        // Legacy fallback: user has no org yet, try pivot-based hotel lookup
        if (!$hotel && !$org) {
            $hotel = $user->hotel();
        }

        if (!$hotel) {
            return response()->json([
                'errors' => [['code' => 'NO_PROPERTY', 'message' => 'Aucun établissement à configurer.']],
            ], 422);
        }

        if (is_null($hotel->setup_completed_at)) {
            $hotel->update(['setup_completed_at' => now()]);
        }

        return response()->json([
            'data' => [
                'setup_completed'    => true,
                'setup_completed_at' => $hotel->fresh()->setup_completed_at,
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Org du user, avec self-heal : les invités créés avant que l'invitation ne
     * renseigne organization_id sont reliés via leur propriété (pivot), et le
     * lien est persisté pour les prochains appels (même correction silencieuse
     * que OrganizationController::show).
     */
    private function resolveOrganization($user): ?Organization
    {
        if ($user->organization) {
            return $user->organization;
        }

        $viaHotel = $user->hotels()->whereNotNull('hotels.organization_id')
            ->orderBy('user_hotels.granted_at')->first();

        if (!$viaHotel) {
            return null;
        }

        $org = Organization::find($viaHotel->organization_id);
        if ($org) {
            $user->update(['organization_id' => $org->id]);
        }

        return $org;
    }

    private function resolveHotel(Request $request, $org)
    {
        $requestedId = $request->header('X-Property-Id');

        if ($requestedId) {
            $hotel = $org->properties()->where('id', $requestedId)->first();
            if ($hotel) return $hotel;
        }

        return $org->properties()
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first()
            ?? $org->properties()->orderBy('created_at')->first();
    }
}
