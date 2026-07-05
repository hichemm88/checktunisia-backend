<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Properties the current user (hotel_admin or receptionist) is attached to, for the
 * property switcher. Unlike OrganizationController (hotel_admin-only, full org CRUD),
 * this reflects only what *this* account can actually access.
 */
class MyPropertiesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $properties = $request->user()->hotels()->orderBy('hotels.created_at')->get();

        return response()->json(['data' => $properties->map(fn($h) => [
            'id'     => $h->id,
            'name'   => $h->name,
            'status' => $h->status,
        ])->values()]);
    }
}
