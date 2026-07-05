<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant (hotel/property) and organization from the authenticated user.
 *
 * Multi-property logic (same for hotel_admin and receptionist):
 *  - A user can be attached to one or several properties via the `user_hotels` pivot
 *    (hotel_admin gets a pivot row for every property they own/create; receptionist gets
 *    a pivot row for each property their manager assigns them to).
 *  - The active property is whichever one the `X-Property-Id` header names, as long as the
 *    user actually has a pivot row for it. Otherwise it defaults to the first active property
 *    among the ones they're attached to (oldest first), then any attached property.
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

        $org = $user->organization_id
            ? Organization::find($user->organization_id)
            : null;

        $accessible = $user->hotels()->orderBy('hotels.created_at')->get();

        // Safety net for accounts whose pivot rows never got backfilled when the
        // multi-property/org architecture was introduced — falls back to "any org property"
        // instead of locking the account out entirely.
        if ($accessible->isEmpty() && $org) {
            $accessible = $org->properties()->orderBy('created_at')->get();
        }

        $requestedPropertyId = $request->header('X-Property-Id');
        $hotel = $requestedPropertyId
            ? $accessible->firstWhere('id', $requestedPropertyId)
            : null;

        if (!$hotel) {
            $hotel = $accessible->firstWhere('status', 'active') ?? $accessible->first();
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
