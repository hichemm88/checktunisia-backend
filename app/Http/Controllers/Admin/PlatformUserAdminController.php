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
        $tempPassword = Str::random(12);

        $user = DB::transaction(function () use ($v, $hotel, $tempPassword) {
            $u = User::create([
                'first_name'        => $v['first_name'],
                'last_name'         => $v['last_name'],
                'email'             => $v['email'],
                'password'          => Hash::make($tempPassword),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $u->assignRole($v['role']);
            $u->hotels()->attach($hotel->id, ['granted_at' => now()]);
            AuditLogger::log('user.created', $u, [], $u->only(['email', 'first_name', 'last_name']), hotelId: $hotel->id);
            return $u;
        });

        SystemMailer::send('welcome', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'hotel_name' => $hotel->name,
            'role_label' => $v['role'] === 'hotel_admin' ? 'Administrateur' : 'Réceptionniste',
            'credentials_box' => SystemMailer::credentialsBox($user->email, $tempPassword),
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::loginUrl()),
        ]);

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
        $user->delete();
        AuditLogger::log('user.deleted', $user);

        return response()->json(null, 204);
    }

    public function resendInvite(string $id): JsonResponse
    {
        $user = User::role(['hotel_admin', 'receptionist'])->with('hotels')->findOrFail($id);
        $hotel = $user->hotels->first();
        $tempPassword = Str::random(12);
        $user->update(['password' => Hash::make($tempPassword)]);
        AuditLogger::log('user.invite_resent', $user);

        $sent = SystemMailer::send('welcome', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'hotel_name' => $user->hotels->pluck('name')->implode(', ') ?: ($hotel->name ?? '—'),
            'role_label' => $user->primary_role === 'hotel_admin' ? 'Administrateur' : 'Réceptionniste',
            'credentials_box' => SystemMailer::credentialsBox($user->email, $tempPassword),
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::loginUrl()),
        ]);

        return response()->json(['data' => ['id' => $user->id, 'email_sent' => $sent]]);
    }
}
