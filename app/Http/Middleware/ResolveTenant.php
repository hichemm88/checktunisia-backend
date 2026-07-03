<?php

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant (hotel/property) and organization from the authenticated user.
 *
 * Multi-property logic:
 *  - hotel_admin → organization is resolved via user.organization_id; a specific property can be
 *    selected by sending the `X-Property-Id` UUID header. Defaults to the first active property.
 *  - receptionist → resolved from the user_hotels pivot (fixed assignment to one property).
 *
 * Bound into the service container:
 *  - app('tenant')       → Hotel  (current active property)
 *  - app('organization') → Organization (owning org, or null for legacy data)
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isHotelStaff()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'Tenant access denied.', 'field' => null]],
            ], 403);
        }

        $hotel = null;
        $org   = null;

        if ($user->isHotelAdmin()) {
            // ── hotel_admin: org-aware multi-property resolution ──────────
            $org = $user->organization_id
                ? Organization::find($user->organization_id)
                : null;

            $requestedPropertyId = $request->header('X-Property-Id');

            if ($requestedPropertyId && $org) {
                // Validate the requested property belongs to this org
                $hotel = Hotel::where('id', $requestedPropertyId)
                    ->where('organization_id', $org->id)
                    ->first();
            }

            if (!$hotel) {
                // Fall back to first active property, then any property
                if ($org) {
                    $hotel = $org->properties()
                        ->where('status', 'active')
                        ->orderBy('created_at')
                        ->first()
                        ?? $org->properties()->orderBy('created_at')->first();
                } else {
                    // Legacy: no org yet, use pivot table
                    $hotel = $user->hotel();
                }
            }
        } else {
            // ── receptionist: still resolved from user_hotels pivot ───────
            $hotel = $user->hotel();
            if ($hotel?->organization_id) {
                $org = Organization::find($hotel->organization_id);
            }
        }

        if (!$hotel) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'TENANT_NOT_FOUND', 'message' => 'No property associated with this account.', 'field' => null]],
            ], 404);
        }

        // Bind to service container for use in controllers + other middleware
        $request->merge(['__tenant' => $hotel]);
        app()->instance('tenant', $hotel);
        app()->instance('organization', $org);

        return $next($request);
    }
}
