<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class HotelAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Hotel::with(['activeSubscription.plan', 'address'])
            ->withCount('checkIns');

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search'))  $query->where('name', 'ilike', "%{$request->search}%");

        $hotels = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $hotels->map(fn(Hotel $h) => [
                'id'                  => $h->id,
                'name'                => $h->name,
                'slug'                => $h->slug,
                'type'                => $h->type,
                'room_count'          => $h->room_count,
                'status'              => $h->status,
                'registration_number' => $h->registration_number,
                'subscription'        => $h->activeSubscription ? [
                    'plan'       => $h->activeSubscription->plan?->name,
                    'status'     => $h->activeSubscription->status,
                    'expires_at' => $h->activeSubscription->expires_at,
                ] : null,
                'check_ins_count' => $h->check_ins_count,
                'created_at'      => $h->created_at,
            ]),
            'meta' => ['total' => $hotels->total(), 'current_page' => $hotels->currentPage(), 'per_page' => $hotels->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'type'                => ['required', 'in:hotel,guesthouse,rental,hostel,resort'],
            'room_count'          => ['required', 'integer', 'min:1'],
            'registration_number' => ['nullable', 'string', 'max:100', 'unique:hotels'],
            'stars'               => ['nullable', 'integer', 'between:1,5'],
            'address'             => ['required', 'array'],
            'address.line1'       => ['required', 'string'],
            'address.city'        => ['required', 'string'],
            'address.governorate' => ['required', 'string'],
            'address.postal_code' => ['nullable', 'string'],
            'contacts'            => ['nullable', 'array'],
            'contacts.*.type'     => ['required', 'string'],
            'contacts.*.value'    => ['required', 'string'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        $hotel = DB::transaction(function () use ($validated, $request) {
            $hotel = Hotel::create([
                'name'                => $validated['name'],
                'type'                => $validated['type'],
                'room_count'          => $validated['room_count'],
                'registration_number' => $validated['registration_number'] ?? null,
                'stars'               => $validated['stars'] ?? null,
                'status'              => 'active',
                'created_by'          => $request->user()->id,
            ]);

            HotelAddress::create(array_merge($validated['address'], ['hotel_id' => $hotel->id, 'is_primary' => true]));

            foreach ($validated['contacts'] ?? [] as $contact) {
                HotelContact::create(array_merge($contact, ['hotel_id' => $hotel->id]));
            }

            AuditLogger::log('hotel.created', $hotel, [], $hotel->toArray());
            return $hotel;
        });

        return response()->json(['data' => $hotel->load(['address', 'contacts'])], 201);
    }

    public function show(string $id): JsonResponse
    {
        $hotel = Hotel::with(['address', 'contacts', 'activeSubscription.plan'])->findOrFail($id);

        return response()->json(['data' => array_merge($hotel->toArray(), [
            'active_subscription' => $hotel->activeSubscription,
        ])]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $old   = $hotel->toArray();

        $validated = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'room_count' => ['sometimes', 'integer', 'min:1'],
            'status'     => ['sometimes', 'in:active,suspended,pending,closed'],
            'stars'      => ['nullable', 'integer', 'between:1,5'],
        ]);

        $hotel->update($validated);
        AuditLogger::log('hotel.updated', $hotel, $old, $hotel->fresh()->toArray());

        return response()->json(['data' => $hotel->fresh()]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];

        $hotel->update(['status' => 'suspended']);

        // Also suspend the active subscription
        if ($sub = $hotel->activeSubscription) {
            $sub->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => $reason]);
            // Bust cache
            cache()->forget("hotel_subscription_active:{$hotel->id}");
        }

        AuditLogger::log('hotel.suspended', $hotel, [], ['reason' => $reason]);

        return response()->json(['data' => ['status' => 'suspended', 'suspended_at' => now()]]);
    }

    public function activate(string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'active']);
        cache()->forget("hotel_subscription_active:{$hotel->id}");
        AuditLogger::log('hotel.activated', $hotel);

        return response()->json(['data' => ['status' => 'active']]);
    }

    public function getUsers(string $hotelId): JsonResponse
    {
        $hotel = Hotel::findOrFail($hotelId);
        $users = $hotel->users()->with('roles')->get();

        return response()->json(['data' => $users->map(fn($u) => [
            'id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'email' => $u->email, 'role' => $u->primary_role, 'status' => $u->status,
            'last_login_at' => $u->last_login_at,
        ])]);
    }

    public function createUser(Request $request, string $hotelId): JsonResponse
    {
        $hotel = Hotel::findOrFail($hotelId);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'role'       => ['required', 'in:hotel_admin,receptionist'],
            'password'   => ['required', 'string', 'min:8'],
        ]);

        $user = DB::transaction(function () use ($validated, $hotel) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'password'   => Hash::make($validated['password']),
                'status'     => 'active',
                'email_verified_at' => now(),
            ]);

            $user->assignRole($validated['role']);
            $hotel->users()->attach($user->id, ['granted_at' => now()]);

            AuditLogger::log('user.created', $user, [], $user->only(['email', 'first_name', 'last_name']));
            return $user;
        });

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email, 'role' => $user->primary_role]], 201);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'data' => [
                'hotels' => [
                    'total'     => Hotel::count(),
                    'active'    => Hotel::where('status', 'active')->count(),
                    'suspended' => Hotel::where('status', 'suspended')->count(),
                    'pending'   => Hotel::where('status', 'pending')->count(),
                ],
                'check_ins' => [
                    'today'      => \App\Models\CheckIn::whereDate('created_at', today())->count(),
                    'this_month' => \App\Models\CheckIn::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
                ],
            ],
        ]);
    }
}
