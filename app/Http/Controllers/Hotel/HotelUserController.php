<?php
namespace App\Http\Controllers\Hotel;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class HotelUserController extends Controller {
    /**
     * Hotel IDs the current hotel_admin may manage. A hotel_admin can own several
     * properties under the same organization (see ResolveTenant); staff aren't
     * necessarily attached to the currently *active* property, so any listing/
     * management query must span every property of the org, not just app('tenant').
     */
    private function manageableHotelIds(): Collection {
        // Note: read organization_id off the tenant model rather than app('organization') —
        // Laravel's container stores that binding via instance() even when the org is null,
        // and isset()-based lookups in Container::resolve() treat a bound null as "not bound",
        // so app('organization') throws "Target class [organization] does not exist" instead
        // of returning null for hotels/users with no organization yet.
        $hotel = app('tenant');
        return $hotel->organization_id
            ? Hotel::where('organization_id', $hotel->organization_id)->pluck('id')
            : collect([$hotel->id]);
    }

    /** Users attached to any of the org's properties, with their per-property assignments. */
    private function manageableUsersQuery(Collection $hotelIds) {
        return User::whereHas('hotels', fn($q) => $q->whereIn('hotels.id', $hotelIds))
            ->with(['roles', 'hotels' => fn($q) => $q->whereIn('hotels.id', $hotelIds)]);
    }

    public function index(): JsonResponse {
        $hotelIds = $this->manageableHotelIds();
        $users = $this->manageableUsersQuery($hotelIds)->get();
        return response()->json(['data' => $users->map(fn($u) => [
            'id'            => $u->id,
            'first_name'    => $u->first_name,
            'last_name'     => $u->last_name,
            'email'         => $u->email,
            'role'          => $u->primary_role,
            'status'        => $u->status,
            'last_login_at' => $u->last_login_at,
            'properties'    => $u->hotels->map(fn($h) => ['id' => $h->id, 'name' => $h->name])->values(),
        ])]);
    }

    public function store(Request $request): JsonResponse {
        $hotel = app('tenant');
        $manageableIds = $this->manageableHotelIds();
        $v = $request->validate([
            'first_name' => ['required','string','max:100'],
            'last_name'  => ['required','string','max:100'],
            'email'      => ['required','email','unique:users,email'],
            'role'       => ['required','in:hotel_admin,receptionist'],
            // Which propertie(s) to grant access to. Defaults to the currently active one.
            // A staff member (receptionist or admin) can be assigned several properties at once.
            'hotel_ids'   => ['sometimes','array','min:1'],
            'hotel_ids.*' => ['string', Rule::in($manageableIds->all())],
        ]);

        $targetHotelIds = !empty($v['hotel_ids']) ? $v['hotel_ids'] : [$hotel->id];
        $tempPassword = Str::random(12);

        $user = DB::transaction(function() use ($v, $targetHotelIds, $tempPassword) {
            $u = User::create([
                'first_name'        => $v['first_name'],
                'last_name'         => $v['last_name'],
                'email'             => $v['email'],
                'password'          => Hash::make($tempPassword),
                'status'            => 'active',
                'email_verified_at' => now(),
            ]);
            $u->assignRole($v['role']);
            $u->hotels()->attach($targetHotelIds, ['granted_at' => now()]);
            AuditLogger::log('user.created', $u, [], $u->only(['email','first_name','last_name']), hotelId: $targetHotelIds[0]);
            return $u;
        });

        $assignedNames = Hotel::whereIn('id', $targetHotelIds)->pluck('name')->implode(', ');
        $this->sendWelcomeEmail($user, $tempPassword, $assignedNames, $v['role']);

        return response()->json(['data' => [
            'id'    => $user->id,
            'email' => $user->email,
            'role'  => $user->primary_role,
        ]], 201);
    }

    public function update(Request $request, string $id): JsonResponse {
        $manageableIds = $this->manageableHotelIds();
        $user = $this->manageableUsersQuery($manageableIds)->findOrFail($id);
        $hotel = $user->hotels->first() ?? app('tenant');
        $v = $request->validate([
            'first_name' => ['sometimes','string','max:100'],
            'last_name'  => ['sometimes','string','max:100'],
            'role'       => ['sometimes','in:hotel_admin,receptionist'],
            'status'     => ['sometimes','in:active,inactive,suspended'],
            // Full replacement of this user's property assignments.
            'hotel_ids'   => ['sometimes','array','min:1'],
            'hotel_ids.*' => ['string', Rule::in($manageableIds->all())],
        ]);
        $fields = array_filter([
            'first_name' => $v['first_name'] ?? null,
            'last_name'  => $v['last_name']  ?? null,
            'status'     => $v['status']     ?? null,
        ], fn($val) => $val !== null);
        if ($fields) { $user->update($fields); }
        if (isset($v['role'])) { $user->syncRoles([$v['role']]); }
        if (isset($v['hotel_ids'])) {
            $user->hotels()->sync(array_fill_keys($v['hotel_ids'], ['granted_at' => now()]));
        }
        // A suspended/deactivated account must lose access immediately, not
        // whenever its existing tokens happen to expire (up to 8h later).
        if (isset($v['status']) && $v['status'] !== 'active') {
            $user->tokens()->delete();
        }
        AuditLogger::log('user.updated', $user, newValues: $user->only(['email', 'first_name', 'last_name']), hotelId: $hotel->id);
        return response()->json(['data' => [
            'id'         => $user->id,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'role'       => $user->primary_role,
            'status'     => $user->status,
            'properties' => $user->hotels()->get()->map(fn($h) => ['id' => $h->id, 'name' => $h->name])->values(),
        ]]);
    }

    public function destroy(string $id): JsonResponse {
        $user = $this->manageableUsersQuery($this->manageableHotelIds())->findOrFail($id);
        $hotel = $user->hotels->first() ?? app('tenant');
        $user->update(['status'=>'inactive']);
        $user->tokens()->delete();
        $old = $user->only(['email', 'first_name', 'last_name']);
        $user->delete();
        AuditLogger::log('user.deleted', $user, $old, hotelId: $hotel->id);
        return response()->json(null, 204);
    }

    /**
     * Re-send the welcome email with a fresh temporary password. Used when the
     * original welcome email never arrived (mail misconfiguration, typo, etc.)
     * so the account doesn't need to be recreated — it already exists and is active.
     */
    public function resendInvite(string $id): JsonResponse {
        $hotelIds = $this->manageableHotelIds();
        $user = $this->manageableUsersQuery($hotelIds)->findOrFail($id);
        $hotel = $user->hotels->first() ?? app('tenant');
        $assignedNames = $user->hotels->pluck('name')->implode(', ') ?: $hotel->name;

        $tempPassword = Str::random(12);
        $user->update(['password' => Hash::make($tempPassword)]);
        AuditLogger::log('user.invite_resent', $user, newValues: $user->only(['email', 'first_name', 'last_name']), hotelId: $hotel->id);

        $sent = $this->sendWelcomeEmail($user, $tempPassword, $assignedNames, $user->primary_role);

        return response()->json(['data' => [
            'id'         => $user->id,
            'email_sent' => $sent,
        ]]);
    }

    private function sendWelcomeEmail(User $user, string $tempPassword, string $hotelName, string $role): bool {
        return \App\Services\Email\SystemMailer::send('welcome', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'hotel_name' => $hotelName,
            'role_label' => $role === 'hotel_admin' ? 'Administrateur' : 'Réceptionniste',
            'credentials_box' => \App\Services\Email\SystemMailer::credentialsBox($user->email, $tempPassword),
            'cta_button'      => \App\Services\Email\SystemMailer::ctaButton(\App\Services\Email\SystemMailer::loginUrl()),
        ]);
    }
}
