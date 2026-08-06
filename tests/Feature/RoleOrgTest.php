<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\RoleOrgMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Rôles hôtel (role_org) & fix du gate d'onboarding.
 *
 * Couvre les 6 tests obligatoires de la spec :
 *  1. Bug repro : 2e hotel_admin d'une org avec établissements → dashboard.
 *  2. Admin sur org vide → « Configuration en attente », pas l'onboarding.
 *  3. Admin sur les endpoints owner-only → 403 ROLE_ORG_FORBIDDEN.
 *  4. Un seul owner par organisation (contrainte DB).
 *  5. Transfert d'ownership atomique.
 *  6. Migration des données existantes (owner = créateur / plus ancien).
 */
class RoleOrgTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(array $attrs = []): Organization
    {
        return Organization::create(array_merge([
            'name'          => 'Org Test',
            'entity_type'   => 'company',
            'contact_email' => 'owner@example.test',
            'status'        => 'active',
        ], $attrs));
    }

    private function makeHotel(Organization $org, array $attrs = []): Hotel
    {
        return Hotel::factory()->withActiveSubscription()->create(array_merge([
            'organization_id'    => $org->id,
            'setup_completed_at' => now(),
        ], $attrs));
    }

    private function makeOwner(Organization $org, ?Hotel $hotel = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'role_org'        => 'owner',
            'email'           => $org->contact_email,
        ], $attrs));
        $user->assignRole('hotel_admin');
        if ($hotel) $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        return $user;
    }

    private function makeOrgAdmin(Organization $org, ?Hotel $hotel = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'role_org'        => 'admin',
        ], $attrs));
        $user->assignRole('hotel_admin');
        if ($hotel) $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        return $user;
    }

    // ── 1. Reproduction du bug ────────────────────────────────────────────────

    public function test_second_admin_of_org_with_property_lands_on_dashboard(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $this->makeOwner($org, $hotel);
        $second = $this->makeOrgAdmin($org, $hotel);

        $this->actingAs($second)
            ->getJson('/api/v1/hotel/onboarding/status')
            ->assertOk()
            ->assertJsonPath('data.has_property', true)
            ->assertJsonPath('data.establishments_count', 1)
            ->assertJsonPath('data.role_org', 'admin');
    }

    /**
     * Données d'avant le fix : l'invité n'a PAS d'organization_id (seulement le
     * pivot user_hotels). Le statut doit quand même être scopé org — c'était la
     * cause racine du renvoi vers l'onboarding.
     */
    public function test_legacy_invitee_without_organization_id_still_sees_org_properties(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $this->makeOwner($org, $hotel);

        $legacy = User::factory()->hotelAdmin($hotel)->create(); // ni organization_id ni role_org

        $this->actingAs($legacy)
            ->getJson('/api/v1/hotel/onboarding/status')
            ->assertOk()
            ->assertJsonPath('data.has_property', true)
            ->assertJsonPath('data.establishments_count', 1);

        // Self-heal : le lien org et le role_org ont été persistés.
        $legacy->refresh();
        $this->assertSame($org->id, $legacy->organization_id);
        $this->assertSame('admin', $legacy->role_org); // l'owner existe déjà

        // L'owner reste unique.
        $this->assertSame(1, User::where('organization_id', $org->id)->where('role_org', 'owner')->count());
    }

    // ── 2. Admin sur org vide ─────────────────────────────────────────────────

    public function test_admin_on_empty_org_gets_pending_setup_signal_not_onboarding(): void
    {
        $org = $this->makeOrg();
        $this->makeOwner($org);
        $admin = $this->makeOrgAdmin($org);

        // has_property=false + role_org=admin → le front affiche « Configuration
        // en attente » (jamais l'onboarding, réservé à l'owner).
        $this->actingAs($admin)
            ->getJson('/api/v1/hotel/onboarding/status')
            ->assertOk()
            ->assertJsonPath('data.has_property', false)
            ->assertJsonPath('data.establishments_count', 0)
            ->assertJsonPath('data.role_org', 'admin');
    }

    public function test_owner_on_empty_org_is_directed_to_onboarding(): void
    {
        $org   = $this->makeOrg();
        $owner = $this->makeOwner($org);

        $this->actingAs($owner)
            ->getJson('/api/v1/hotel/onboarding/status')
            ->assertOk()
            ->assertJsonPath('data.has_property', false)
            ->assertJsonPath('data.role_org', 'owner');
    }

    // ── 3. Endpoints owner-only → 403 pour un admin ───────────────────────────

    public function test_admin_cannot_use_onboarding_or_property_creation_endpoints(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $this->makeOwner($org, $hotel);
        $admin = $this->makeOrgAdmin($org, $hotel);

        $this->actingAs($admin)
            ->postJson('/api/v1/hotel/onboarding/complete')
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'ROLE_ORG_FORBIDDEN');

        $this->actingAs($admin)
            ->postJson('/api/v1/hotel/organization/properties', ['name' => 'Nouveau riad'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'ROLE_ORG_FORBIDDEN');

        $this->actingAs($admin)
            ->patchJson('/api/v1/hotel/organization', ['name' => 'Autre nom'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/hotel/organization/properties/{$hotel->id}")
            ->assertForbidden();

        // Facturation
        $this->actingAs($admin)
            ->getJson('/api/v1/hotel/invoices')
            ->assertForbidden();

        // Gestion des utilisateurs
        $this->actingAs($admin)
            ->postJson('/api/v1/hotel/users', [
                'first_name' => 'X', 'last_name' => 'Y',
                'email' => 'nouveau@example.test', 'role' => 'receptionist',
            ])
            ->assertForbidden();

        // Transfert d'ownership
        $this->actingAs($admin)
            ->postJson('/api/v1/hotel/organization/transfer-ownership', [
                'user_id' => $admin->id, 'password' => 'Password1!Test',
            ])
            ->assertForbidden();
    }

    public function test_owner_endpoints_remain_accessible_to_owner(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $owner = $this->makeOwner($org, $hotel);

        $this->actingAs($owner)->getJson('/api/v1/hotel/users')->assertOk();
        $this->actingAs($owner)->getJson('/api/v1/hotel/invoices')->assertOk();
    }

    public function test_admin_keeps_operational_access(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $this->makeOwner($org, $hotel);
        $admin = $this->makeOrgAdmin($org, $hotel);

        // Tableau de bord, historique check-ins, lecture org : accessibles.
        $this->actingAs($admin)->getJson('/api/v1/hotel/dashboard')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/hotel/check-ins')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/hotel/organization')->assertOk();
    }

    // ── 4. Un seul owner par organisation ─────────────────────────────────────

    public function test_database_rejects_a_second_owner_in_same_org(): void
    {
        $org   = $this->makeOrg();
        $this->makeOwner($org);
        $admin = $this->makeOrgAdmin($org);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $admin->update(['role_org' => 'owner']); // sans transfert → rejet DB
    }

    public function test_owner_cannot_be_demoted_or_deleted_via_team_endpoints(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $owner = $this->makeOwner($org, $hotel);

        $this->actingAs($owner)
            ->patchJson("/api/v1/hotel/users/{$owner->id}", ['role' => 'receptionist'])
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'ROLE_ORG_FORBIDDEN');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/hotel/users/{$owner->id}")
            ->assertForbidden();

        $this->assertSame('owner', $owner->fresh()->role_org);
    }

    // ── 5. Transfert d'ownership ──────────────────────────────────────────────

    public function test_ownership_transfer_swaps_roles_atomically(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $owner = $this->makeOwner($org, $hotel);
        $admin = $this->makeOrgAdmin($org, $hotel);

        $this->actingAs($owner)
            ->postJson('/api/v1/hotel/organization/transfer-ownership', [
                'user_id' => $admin->id,
                'password' => 'Password1!Test', // mot de passe par défaut de la factory
            ])
            ->assertOk()
            ->assertJsonPath('data.new_owner.id', $admin->id);

        $this->assertSame('admin', $owner->fresh()->role_org);
        $this->assertSame('owner', $admin->fresh()->role_org);
        $this->assertSame(1, User::where('organization_id', $org->id)->where('role_org', 'owner')->count());
    }

    public function test_ownership_transfer_requires_correct_password(): void
    {
        $org   = $this->makeOrg();
        $owner = $this->makeOwner($org);
        $admin = $this->makeOrgAdmin($org);

        $this->actingAs($owner)
            ->postJson('/api/v1/hotel/organization/transfer-ownership', [
                'user_id' => $admin->id, 'password' => 'mauvais-mot-de-passe',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'INVALID_PASSWORD');

        $this->assertSame('owner', $owner->fresh()->role_org);
        $this->assertSame('admin', $admin->fresh()->role_org);
    }

    public function test_ownership_transfer_rejects_target_outside_org(): void
    {
        $org      = $this->makeOrg();
        $owner    = $this->makeOwner($org);
        $otherOrg = $this->makeOrg(['contact_email' => 'autre@example.test']);
        $outsider = $this->makeOrgAdmin($otherOrg, null, ['email' => 'outsider@example.test']);

        $this->actingAs($owner)
            ->postJson('/api/v1/hotel/organization/transfer-ownership', [
                'user_id' => $outsider->id, 'password' => 'Password1!Test',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'TRANSFER_TARGET_INVALID');
    }

    /**
     * Atomicité : si la promotion du nouveau owner échoue en cours de
     * transaction, la rétrogradation de l'ancien est annulée — l'organisation
     * n'est jamais laissée sans owner.
     */
    public function test_failed_transfer_leaves_exactly_one_owner(): void
    {
        $org   = $this->makeOrg();
        $owner = $this->makeOwner($org);
        $admin = $this->makeOrgAdmin($org);

        // Fait échouer la 2e écriture (promotion de la cible) au milieu de la
        // transaction du contrôleur.
        User::updating(function (User $model) use ($admin) {
            if ($model->id === $admin->id && $model->isDirty('role_org')) {
                throw new \RuntimeException('Échec simulé en cours de transaction');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($owner)
                ->postJson('/api/v1/hotel/organization/transfer-ownership', [
                    'user_id' => $admin->id, 'password' => 'Password1!Test',
                ]);
            $this->fail('Le transfert aurait dû échouer.');
        } catch (\RuntimeException) {
            // attendu
        }

        $this->assertSame('owner', $owner->fresh()->role_org);
        $this->assertSame('admin', $admin->fresh()->role_org);
        $this->assertSame(1, User::where('organization_id', $org->id)->where('role_org', 'owner')->count());
    }

    // ── 6. Migration des données existantes ───────────────────────────────────

    public function test_migration_assigns_exactly_one_owner_per_org(): void
    {
        $org   = $this->makeOrg(['contact_email' => 'createur@example.test']);
        $hotel = $this->makeHotel($org);

        // Trois hotel_admin legacy : pivot seulement, ni organization_id ni role_org.
        $oldest  = User::factory()->hotelAdmin($hotel)->create(['created_at' => now()->subYear()]);
        $creator = User::factory()->hotelAdmin($hotel)->create(['email' => 'createur@example.test', 'created_at' => now()->subMonths(6)]);
        $recent  = User::factory()->hotelAdmin($hotel)->create(['created_at' => now()->subDay()]);

        $changed = RoleOrgMigrator::apply();
        $this->assertGreaterThan(0, $changed);

        // Le créateur (email = contact org) devient owner, pas le plus ancien.
        $this->assertSame('owner', $creator->fresh()->role_org);
        $this->assertSame('admin', $oldest->fresh()->role_org);
        $this->assertSame('admin', $recent->fresh()->role_org);
        $this->assertSame($org->id, $oldest->fresh()->organization_id);

        $this->assertSame(1, User::where('organization_id', $org->id)->where('role_org', 'owner')->count());

        // Idempotence : une seconde exécution ne change rien.
        $this->assertSame(0, RoleOrgMigrator::apply());
        $this->assertSame('owner', $creator->fresh()->role_org);
    }

    public function test_migration_falls_back_to_oldest_admin_when_no_creator_match(): void
    {
        $org   = $this->makeOrg(['contact_email' => 'inconnu@example.test']);
        $hotel = $this->makeHotel($org);

        $oldest = User::factory()->hotelAdmin($hotel)->create(['created_at' => now()->subYear()]);
        $recent = User::factory()->hotelAdmin($hotel)->create(['created_at' => now()->subDay()]);

        RoleOrgMigrator::apply();

        $this->assertSame('owner', $oldest->fresh()->role_org);
        $this->assertSame('admin', $recent->fresh()->role_org);
    }

    public function test_migration_dry_run_writes_nothing(): void
    {
        $org   = $this->makeOrg();
        $hotel = $this->makeHotel($org);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        Artisan::call('org:migrate-role-org', ['--dry-run' => true]);

        $this->assertNull($user->fresh()->role_org);
        $this->assertNull($user->fresh()->organization_id);

        // Sans dry-run, la commande applique.
        Artisan::call('org:migrate-role-org');
        $this->assertSame('owner', $user->fresh()->role_org);
        $this->assertSame($org->id, $user->fresh()->organization_id);
    }

    // ── Régression : flux du premier utilisateur ──────────────────────────────

    public function test_registration_creates_owner(): void
    {
        \App\Models\SubscriptionPlan::firstOrCreate(
            ['slug' => 'standard'],
            ['name' => 'Standard', 'min_rooms' => 1, 'price_monthly' => 99.0, 'currency' => 'TND',
             'features' => [], 'is_active' => true, 'is_public' => true, 'sort_order' => 1],
        );

        $this->postJson('/api/v1/public/register', [
            'org_name'    => 'Dar Test',
            'entity_type' => 'company',
            'first_name'  => 'Ali',
            'last_name'   => 'Ben Salah',
            'email'       => 'ali@example.test',
            'password'    => 'Password1!Test',
            'password_confirmation' => 'Password1!Test',
            'plan_slug'   => 'standard',
        ])->assertStatus(201);

        $user = User::where('email', 'ali@example.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('owner', $user->role_org);
        $this->assertNotNull($user->organization_id);
    }
}
