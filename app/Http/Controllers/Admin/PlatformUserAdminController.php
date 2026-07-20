<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Email\SystemMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Global staff directory (hotel_admin + receptionist) across every hébergeur/établissement. */
class PlatformUserAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::role(['hotel_admin', 'receptionist'])
            ->with(['roles', 'hotels', 'organization']);

        if ($request->filled('role'))   $query->role($request->string('role'));
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn($q) => $q
                ->where('first_name', 'ilike', "%{$search}%")
                ->orWhere('last_name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%"));
        }
        if ($request->filled('organization_id')) {
            $orgId = $request->string('organization_id');
            $query->where(fn($q) => $q
                ->where('organization_id', $orgId)
                ->orWhereHas('hotels', fn($h) => $h->where('organization_id', $orgId)));
        }
        if ($request->filled('hotel_id')) {
            $hotelId = $request->string('hotel_id');
            $query->whereHas('hotels', fn($h) => $h->where('hotels.id', $hotelId));
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $users->map(fn(User $u) => [
                'id'            => $u->id,
                'first_name'    => $u->first_name,
                'last_name'     => $u->last_name,
                'email'         => $u->email,
                'role'          => $u->primary_role,
                'status'        => $u->status,
                'organization'  => $u->organization?->name,
                'hotels'        => $u->hotels->map(fn($h) => ['id' => $h->id, 'name' => $h->name])->values(),
                'last_login_at' => $u->last_login_at,
                'created_at'    => $u->created_at,
            ]),
            'meta' => ['total' => $users->total(), 'current_page' => $users->currentPage(), 'per_page' => $users->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'role'       => ['required', 'in:hotel_admin,receptionist'],
            'hotel_id'   => ['required', 'uuid', 'exists:hotels,id'],
        ]);

        $hotel = Hotel::findOrFail($v['hotel_id']);

        $user = DB::transaction(function () use ($v, $hotel) {
            $u = User::create([
                'first_name'        => $v['first_name'],
                'last_name'         => $v['last_name'],
                'email'             => $v['email'],
                // Unusable random password — the account has no real credential
                // until the invitee sets one via the emailed set-password link.
                'password'          => Hash::make(Str::random(40)),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $u->assignRole($v['role']);
            $u->hotels()->attach($hotel->id, ['granted_at' => now()]);
            AuditLogger::log('user.created', $u, [], $u->only(['email', 'first_name', 'last_name']), hotelId: $hotel->id);
            return $u;
        });

        $locale = $user->locale ?? 'fr';
        SystemMailer::send('welcome', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'hotel_name' => $hotel->name,
            'role_label' => SystemMailer::label($v['role'] === 'hotel_admin' ? 'role_admin' : 'role_receptionist', $locale),
            'cta_button' => SystemMailer::ctaButton(SystemMailer::issueSetPasswordLink($user), SystemMailer::label('set_password', $locale)),
        ], $locale);

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email, 'role' => $user->primary_role]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::role(['hotel_admin', 'receptionist'])->findOrFail($id);
        $v = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'status'     => ['sometimes', 'in:active,inactive,suspended'],
            'role'       => ['sometimes', 'in:hotel_admin,receptionist'],
        ]);

        $fields = array_filter([
            'first_name' => $v['first_name'] ?? null,
            'last_name'  => $v['last_name']  ?? null,
            'status'     => $v['status']     ?? null,
        ], fn($val) => $val !== null);
        if ($fields) $user->update($fields);
        if (isset($v['role'])) $user->syncRoles([$v['role']]);

        // A suspended/deactivated account must lose access immediately, not
        // whenever its existing tokens happen to expire (up to 8h later).
        if (isset($v['status']) && $v['status'] !== 'active') {
            $user->tokens()->delete();
        }

        AuditLogger::log('user.updated', $user);

        return response()->json(['data' => [
            'id' => $user->id, 'first_name' => $user->first_name, 'last_name' => $user->last_name,
            'role' => $user->primary_role, 'status' => $user->status,
        ]]);
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::role(['hotel_admin', 'receptionist'])->findOrFail($id);
        $user->update(['status' => 'inactive']);
        $user->tokens()->delete();
        $user->delete();
        AuditLogger::log('user.deleted', $user);

        return response()->json(null, 204);
    }

    public function resendInvite(string $id): JsonResponse
    {
        $user = User::role(['hotel_admin', 'receptionist'])->with('hotels')->findOrFail($id);
        $hotel = $user->hotels->first();
        // Invalidate whatever credential existed before (never set, or a stale
        // temp password from an earlier invite) so only the new link works.
        $user->update(['password' => Hash::make(Str::random(40))]);
        AuditLogger::log('user.invite_resent', $user);

        $locale = $user->locale ?? 'fr';
        $sent = SystemMailer::send('welcome', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'hotel_name' => $user->hotels->pluck('name')->implode(', ') ?: ($hotel->name ?? '—'),
            'role_label' => SystemMailer::label($user->primary_role === 'hotel_admin' ? 'role_admin' : 'role_receptionist', $locale),
            'cta_button' => SystemMailer::ctaButton(SystemMailer::issueSetPasswordLink($user), SystemMailer::label('set_password', $locale)),
        ], $locale);

        return response()->json(['data' => ['id' => $user->id, 'email_sent' => $sent]]);
    }
}
