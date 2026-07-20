<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KPIs business admin (GET /admin/metrics/kpis) : MRR + mouvement, ARPU, churn
 * logo, activation, conversion d'essai. Verifie le calcul et le guard.
 */
class AdminKpiTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): SubscriptionPlan
    {
        $this->seed(SubscriptionPlanSeeder::class);

        return SubscriptionPlan::query()->orderBy('sort_order')->first();
    }

    /** Org + abonnement en un appel. custom_price rend le MRR deterministe. */
    private function customerWithSub(array $subOverride = []): Organization
    {
        $org = Organization::create([
            'name' => fake()->company(), 'entity_type' => 'company',
            'contact_email' => fake()->unique()->safeEmail(), 'status' => 'active',
        ]);

        Subscription::create(array_merge([
            'organization_id' => $org->id,
            'plan_id'         => $this->plan()->id,
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'custom_price'    => 100,
            'auto_renew'      => true,
            'started_at'      => now()->subMonths(2),
            'expires_at'      => now()->addMonth(),
        ], $subOverride));

        return $org;
    }

    public function test_kpis_require_platform_admin(): void
    {
        $hotel = Hotel::factory()->create();
        $receptionist = User::factory()->receptionist($hotel)->create();

        $this->actingAs($receptionist)->getJson('/api/v1/admin/metrics/kpis')->assertForbidden();
    }

    public function test_mrr_arpu_and_paying_customers(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Deux clients payants a 100/mois : MRR 200, ARPU 100.
        $this->customerWithSub(['custom_price' => 100]);
        $this->customerWithSub(['custom_price' => 100, 'billing_cycle' => 'yearly', 'custom_price' => 1200]);

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')
            ->assertOk()->json('data');

        $this->assertSame('TND', $data['currency']);
        $this->assertEqualsWithDelta(200, $data['mrr']['current'], 0.001);
        $this->assertSame(2, $data['arpu']['paying_customers']);
        $this->assertEqualsWithDelta(100, $data['arpu']['value'], 0.001);
    }

    public function test_new_mrr_counts_only_subscriptions_started_this_month(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->customerWithSub(['custom_price' => 80, 'started_at' => now()->subMonths(3)]); // ancien
        $this->customerWithSub(['custom_price' => 120, 'started_at' => now()->startOfMonth()->addDay()]); // ce mois

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(200, $data['mrr']['current'], 0.001);
        $this->assertEqualsWithDelta(120, $data['mrr']['new_this_month'], 0.001);
    }

    public function test_churn_counts_customers_lost_this_month(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Un client actif.
        $this->customerWithSub(['custom_price' => 100]);

        // Un client resilie ce mois, sans autre abonnement actif -> churn.
        $this->customerWithSub([
            'custom_price' => 50,
            'status'       => 'cancelled',
            'cancelled_at' => now()->subDays(2),
        ]);

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')
            ->assertOk()->json('data');

        $this->assertSame(1, $data['churn']['churned_customers']);
        $this->assertSame(2, $data['churn']['base_customers']); // 1 actif + 1 parti
        $this->assertEqualsWithDelta(50.0, $data['churn']['rate_pct'], 0.1);
        $this->assertEqualsWithDelta(50, $data['mrr']['churned_this_month'], 0.001);
        $this->assertEqualsWithDelta(-50, $data['mrr']['net_new_this_month'], 0.001);
    }

    public function test_activation_rate_from_signup_to_first_checkin(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Client active : possede un etablissement avec au moins un check-in.
        $activated = Organization::create([
            'name' => 'Active Co', 'entity_type' => 'company',
            'contact_email' => 'active@test.tn', 'status' => 'active',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $activated->id]);
        CheckIn::factory()->create(['hotel_id' => $hotel->id, 'created_by' => $admin->id]);

        // Client inactif : inscrit mais aucun check-in.
        Organization::create([
            'name' => 'Idle Co', 'entity_type' => 'company',
            'contact_email' => 'idle@test.tn', 'status' => 'active',
        ]);

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')
            ->assertOk()->json('data');

        $this->assertSame(2, $data['activation']['cohort_size']);
        $this->assertSame(1, $data['activation']['activated']);
        $this->assertEqualsWithDelta(50.0, $data['activation']['rate_pct'], 0.1);
    }

    public function test_rates_are_null_when_no_data(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')
            ->assertOk()->json('data');

        $this->assertNull($data['churn']['rate_pct']);
        $this->assertNull($data['activation']['rate_pct']);
        $this->assertSame(0, $data['arpu']['paying_customers']);
        $this->assertEqualsWithDelta(0, $data['mrr']['current'], 0.001);
    }
}
