<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        $hotel = $user->hotel();

        if (!$hotel) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'TENANT_NOT_FOUND', 'message' => 'No hotel associated with this account.', 'field' => null]],
            ], 404);
        }

        // Bind hotel to request for use in controllers
        $request->merge(['__tenant' => $hotel]);
        app()->instance('tenant', $hotel);

        return $next($request);
    }
}
