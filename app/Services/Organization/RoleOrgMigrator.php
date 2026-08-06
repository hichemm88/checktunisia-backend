<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assigns the intra-organization role (`users.role_org`) for hotel accounts.
 *
 * Rules (spec "Rôles hôtel & fix onboarding"):
 *  - Exactly one `owner` per organization. The account creator (user whose
 *    email matches the org's contact_email) becomes owner; when no such user
 *    exists, the oldest hotel_admin of the org does.
 *  - Every other hotel_admin of the org becomes `admin`.
 *  - Receptionists keep role_org = null (the matrix only applies to hotel_admin).
 *
 * Also backfills `users.organization_id` for invited staff created before the
 * invitation flow set it (they were only linked through the user_hotels pivot),
 * which is the root cause of the onboarding redirect bug.
 *
 * Every method is idempotent — safe to run from the schema migration, from the
 * artisan command (with --dry-run), and lazily as a self-heal on login paths.
 */
class RoleOrgMigrator
{
    /**
     * Compute the full plan without writing anything.
     *
     * @return array<int, array{action: string, user_id: string, email: string, organization_id: string, detail: string}>
     */
    public static function plan(): array
    {
        $actions = [];

        foreach (self::orphanStaff() as $user) {
            $orgId = $user->hotels()->whereNotNull('organization_id')
                ->orderBy('user_hotels.granted_at')->first()?->organization_id;
            if ($orgId) {
                $actions[] = [
                    'action'          => 'backfill_organization_id',
                    'user_id'         => $user->id,
                    'email'           => $user->email,
                    'organization_id' => $orgId,
                    'detail'          => 'organization_id hérité de la propriété liée (pivot user_hotels)',
                ];
            }
        }

        foreach (Organization::query()->orderBy('created_at')->get() as $org) {
            foreach (self::planForOrg($org, $actions) as $a) {
                $actions[] = $a;
            }
        }

        return $actions;
    }

    /** Apply the whole plan. Returns the number of users changed. */
    public static function apply(): int
    {
        $changed = 0;

        foreach (self::orphanStaff() as $user) {
            $orgId = $user->hotels()->whereNotNull('organization_id')
                ->orderBy('user_hotels.granted_at')->first()?->organization_id;
            if ($orgId) {
                $user->update(['organization_id' => $orgId]);
                $changed++;
            }
        }

        foreach (Organization::query()->orderBy('created_at')->get() as $org) {
            $changed += self::assignForOrg($org);
        }

        return $changed;
    }

    /**
     * Idempotently assign role_org for one organization's hotel_admins.
     * Safe to call lazily (self-heal) — does nothing when everyone is assigned.
     */
    public static function assignForOrg(Organization $org): int
    {
        $admins = self::hotelAdminsOf($org);
        if ($admins->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($org, $admins) {
            $changed = 0;

            $hasOwner = User::where('organization_id', $org->id)
                ->where('role_org', 'owner')
                ->exists();

            if (!$hasOwner) {
                $owner = self::pickOwner($org, $admins);
                $owner->update(['role_org' => 'owner']);
                Log::info('role_org: owner assigné', ['organization_id' => $org->id, 'user_id' => $owner->id, 'email' => $owner->email]);
                $changed++;
            }

            foreach ($admins as $admin) {
                if (is_null($admin->fresh()->role_org)) {
                    $admin->update(['role_org' => 'admin']);
                    $changed++;
                }
            }

            return $changed;
        });
    }

    /** Role to give a newly created hotel_admin in this org. */
    public static function defaultRoleForNewAdmin(Organization $org): string
    {
        $hasOwner = User::where('organization_id', $org->id)
            ->where('role_org', 'owner')
            ->exists();

        return $hasOwner ? 'admin' : 'owner';
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** Hotel staff missing organization_id but linked to an org via their properties. */
    private static function orphanStaff()
    {
        return User::query()
            ->whereNull('organization_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hotel_admin', 'receptionist']))
            ->whereHas('hotels', fn ($q) => $q->whereNotNull('hotels.organization_id'))
            ->get();
    }

    private static function hotelAdminsOf(Organization $org)
    {
        return User::query()
            ->where('organization_id', $org->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'hotel_admin'))
            ->orderBy('created_at')
            ->get();
    }

    /** Account creator (email == org contact_email), else oldest hotel_admin. */
    private static function pickOwner(Organization $org, $admins): User
    {
        $creator = $admins->first(
            fn (User $u) => $org->contact_email
                && strcasecmp($u->email, $org->contact_email) === 0
        );

        return $creator ?? $admins->first();
    }

    /** plan() companion of assignForOrg() — read-only. */
    private static function planForOrg(Organization $org, array $pendingActions): array
    {
        $actions = [];

        // Include admins whose organization_id backfill is planned above.
        $backfilledIds = collect($pendingActions)
            ->where('action', 'backfill_organization_id')
            ->where('organization_id', $org->id)
            ->pluck('user_id');

        $admins = User::query()
            ->where(fn ($q) => $q->where('organization_id', $org->id)->orWhereIn('id', $backfilledIds))
            ->whereHas('roles', fn ($q) => $q->where('name', 'hotel_admin'))
            ->orderBy('created_at')
            ->get();

        if ($admins->isEmpty()) {
            return [];
        }

        $currentOwner = $admins->first(fn (User $u) => $u->role_org === 'owner');

        if (!$currentOwner) {
            $owner = self::pickOwner($org, $admins);
            $actions[] = [
                'action'          => 'set_owner',
                'user_id'         => $owner->id,
                'email'           => $owner->email,
                'organization_id' => $org->id,
                'detail'          => $org->contact_email && strcasecmp($owner->email, $org->contact_email) === 0
                    ? 'créateur du compte (email = contact organisation)'
                    : 'plus ancien hotel_admin de l\'organisation',
            ];
            $currentOwner = $owner;
        }

        foreach ($admins as $admin) {
            if (is_null($admin->role_org) && $admin->id !== $currentOwner->id) {
                $actions[] = [
                    'action'          => 'set_admin',
                    'user_id'         => $admin->id,
                    'email'           => $admin->email,
                    'organization_id' => $org->id,
                    'detail'          => 'hotel_admin non propriétaire',
                ];
            }
        }

        return $actions;
    }
}
