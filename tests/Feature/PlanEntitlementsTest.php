<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Subscription\PlanEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les features des packs (Admin > Abonnements) sont RÉELLEMENT appliquées :
 * limites utilisateurs/établissements/scans, relais WhatsApp, overrides
 * négociés par client, et exposition aux fronts (web + mobile).
 */
class PlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Hotel $hotel;
    private User $admin;
    private SubscriptionPlan $plan;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Org Entitlements', 'entity_type' => 'company',
            'contact_email' => 'org@test.tn', 'status' => 'active',
        ]);
        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['organization_id' => $this->org->id]);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Testé', 'slug' => 'teste', 'min_rooms' => 1, 'max_rooms' => null,
            'price_monthly' => 59, 'currency' => 'TND', 'is_active' => true, 'sort_order' => 9,
            'features' => ['max_users' => 2, 'max_properties' => 1, 'ocr_scans_per_month' => 100, 'whatsapp_relay' => true],
        ]);
        $this->sub = Subscription::create([
            'organization_id' => $this->org->id, 'plan_id' => $this->plan->id,
            'status' => 'active', 'billing_cycle' => 'monthly',
            'started_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
    }

    // ── Résolution ───────────────────────────────────────────────────────────

    public function test_resolve_merges_plan_and_overrides_with_unlimited_convention(): void
    {
        $effective = PlanEntitlements::resolve($this->org);
        $this->assertSame(2, $effective['max_users']);
        $this->assertSame(1, $effective['max_properties']);
        $this->assertTrue($effective['whatsapp_relay']);

        // Override négocié : -1 = illimité, prime sur le pack.
        $this->sub->update(['metadata' => ['feature_overrides' => ['max_users' => -1, 'whatsapp_relay' => false]]]);
        $effective = PlanEntitlements::resolve($this->org);
        $this->assertNull($effective['max_users']);
        $this->assertFalse($effective['whatsapp_relay']);
        $this->assertSame(1, $effective['max_properties']); // non surchargé → pack
    }

    // ── Application ──────────────────────────────────────────────────────────

    public function test_user_creation_blocked_at_plan_limit(): void
    {
        // max_users = 2 : l'admin + 1. On ajoute le 2e (OK) puis le 3e (bloqué).
        $payload = fn (string $email) => [
            'email' => $email, 'first_name' => 'T', 'last_name' => 'U', 'role' => 'receptionist',
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/users', $payload('u2@test.tn'))
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/users', $payload('u3@test.tn'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'PLAN_LIMIT')
            ->assertJsonPath('errors.0.field', 'max_users');
    }

    public function test_admin_override_lifts_user_limit(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->actingAs($platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/subscriptions/{$this->sub->id}", [
                'feature_overrides' => ['max_users' => -1],
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/users', ['email' => 'u2@test.tn', 'first_name' => 'T', 'last_name' => 'U', 'role' => 'receptionist'])
            ->assertCreated();
        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/users', ['email' => 'u3@test.tn', 'first_name' => 'T', 'last_name' => 'U', 'role' => 'receptionist'])
            ->assertCreated();
    }

    public function test_property_creation_blocked_at_plan_limit(): void
    {
        // max_properties = 1 et l'org a déjà 1 établissement.
        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/organization/properties', [
                'name' => 'Deuxième bien', 'type' => 'villa', 'room_count' => 3,
                'address' => ['line1' => 'Rue X', 'city' => 'Tunis', 'governorate' => 'Tunis'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'PLAN_LIMIT');
    }

    public function test_whatsapp_relay_disabled_by_pack_skips_enqueue(): void
    {
        config(['whatsapp.enabled' => true, 'whatsapp.recipient' => '21612345678@c.us']);
        $this->plan->update(['features' => array_merge($this->plan->features, ['whatsapp_relay' => false])]);

        $checkIn = \App\Models\CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertSame(0, WhatsappSendLog::count());
    }

    // ── Exposition ───────────────────────────────────────────────────────────

    public function test_hotel_subscription_endpoint_exposes_entitlements_with_usage(): void
    {
        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/subscription')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['entitlements']['max_users']['limit']);
        $this->assertSame(1, $data['entitlements']['max_users']['used']);
        $this->assertSame(1, $data['entitlements']['max_properties']['used']);
        $this->assertTrue($data['entitlements']['whatsapp_relay']['enabled']);
    }

    public function test_admin_host_detail_exposes_entitlements_and_overrides(): void
    {
        $this->sub->update(['metadata' => ['feature_overrides' => ['max_users' => 10]]]);

        $platformAdmin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($platformAdmin)
            ->getJson("/api/v1/admin/hosts/{$this->org->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(10, $data['entitlements']['max_users']['limit']);
        $this->assertSame(['max_users' => 10], $data['feature_overrides']);
    }

    public function test_plan_features_validated_against_canonical_keys(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->actingAs($platformAdmin)
            ->patchJson("/api/v1/admin/plans/{$this->plan->id}", [
                'features' => ['max_users' => 'beaucoup'],
            ])
            ->assertUnprocessable();

        $this->actingAs($platformAdmin)
            ->patchJson("/api/v1/admin/plans/{$this->plan->id}", [
                'features' => ['max_users' => 5, 'whatsapp_relay' => false],
            ])
            ->assertOk();
    }
}
