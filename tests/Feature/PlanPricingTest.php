<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscription\PlanPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grille tarifaire par établissement : Multi-sites 199 TND incluant 3
 * établissements, puis +39 TND/mois par établissement supplémentaire.
 * Prix = base + max(0, nb - inclus) × supplément. Aucun montant codé en dur
 * (tout vient de subscription_plans).
 */
class PlanPricingTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPlan $multi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->multi = SubscriptionPlan::create([
            'name' => 'Multi-sites', 'slug' => 'multi-sites', 'scope' => 'organization',
            'min_rooms' => 1, 'max_rooms' => null, 'price_monthly' => 199.000, 'currency' => 'TND',
            'included_properties' => 3, 'extra_property_price' => 39.000,
            'features' => ['max_users' => -1, 'ocr_scans_per_month' => -1], 'is_active' => true, 'sort_order' => 3,
        ]);
    }

    private function orgWith(int $propertyCount, string $cycle = 'monthly', ?float $custom = null): Organization
    {
        $org = Organization::create([
            'name' => "Org {$propertyCount}", 'entity_type' => 'company',
            'contact_email' => "org{$propertyCount}@test.tn", 'status' => 'active',
        ]);
        for ($i = 0; $i < $propertyCount; $i++) {
            Hotel::factory()->create(['organization_id' => $org->id]);
        }
        Subscription::create([
            'organization_id' => $org->id, 'plan_id' => $this->multi->id,
            'status' => 'active', 'billing_cycle' => $cycle, 'custom_price' => $custom,
            'started_at' => now()->subDay(), 'expires_at' => now()->addMonth(),
        ]);
        $org->load('activeSubscription.plan');

        return $org;
    }

    private function subOf(Organization $org): Subscription
    {
        return $org->activeSubscription()->with('plan')->first();
    }

    // ── Critères d'acceptation du prompt ─────────────────────────────────────

    /** 199 TND pour 1, 2 ou 3 établissements (dans les inclus). */
    public function test_price_is_base_within_included_properties(): void
    {
        foreach ([1, 2, 3] as $count) {
            $org = $this->orgWith($count);
            $this->assertSame(199.0, PlanPricing::cycleAmount($this->subOf($org)), "échec pour {$count} établissement(s)");
        }
    }

    /** 316 TND pour 6 établissements (199 + 3 × 39). */
    public function test_price_adds_supplement_beyond_included(): void
    {
        $org = $this->orgWith(6);
        $this->assertSame(316.0, PlanPricing::cycleAmount($this->subOf($org)));
    }

    public function test_detail_breaks_down_base_and_extras(): void
    {
        $d = PlanPricing::detail($this->subOf($this->orgWith(6)));
        $this->assertSame(199.0, $d['base']);
        $this->assertSame(3, $d['included_properties']);
        $this->assertSame(6, $d['property_count']);
        $this->assertSame(3, $d['extra_count']);
        $this->assertSame(117.0, $d['extra_total']);
        $this->assertSame(316.0, $d['monthly_total']);
    }

    /** Annuel : 12 mois au prix de 11, suppléments compris. */
    public function test_yearly_applies_one_month_free_including_extras(): void
    {
        // base 199 × 11 = 2189 ; suppléments 3×39=117/mois × 11 = 1287 ; total 3476.
        $org = $this->orgWith(6, 'yearly');
        $this->assertSame(3476.0, PlanPricing::cycleAmount($this->subOf($org)));
    }

    public function test_custom_price_overrides_the_formula(): void
    {
        $org = $this->orgWith(6, 'monthly', 250.0);
        $sub = $this->subOf($org);
        $this->assertSame(250.0, PlanPricing::cycleAmount($sub));
        $this->assertTrue(PlanPricing::detail($sub)['negotiated']);
    }

    public function test_monthly_value_normalizes_yearly_for_mrr(): void
    {
        // 6 établissements annuel = 3476/12 ≈ 289.667.
        $org = $this->orgWith(6, 'yearly');
        $this->assertSame(289.667, PlanPricing::monthlyValue($this->subOf($org), 6));
    }

    /** Un pack sans extension (extra null) reste au prix de base même au-delà des inclus. */
    public function test_plan_without_extension_stays_at_base(): void
    {
        $pro = SubscriptionPlan::create([
            'name' => 'Pro', 'slug' => 'pro', 'scope' => 'hotel', 'min_rooms' => 6, 'max_rooms' => 20,
            'price_monthly' => 119.000, 'currency' => 'TND', 'included_properties' => 1,
            'extra_property_price' => null, 'is_active' => true, 'sort_order' => 2,
        ]);
        $org = Organization::create(['name' => 'Pro Org', 'entity_type' => 'company', 'contact_email' => 'p@test.tn', 'status' => 'active']);
        Hotel::factory()->create(['organization_id' => $org->id]);
        $sub = Subscription::create([
            'organization_id' => $org->id, 'plan_id' => $pro->id, 'status' => 'active',
            'billing_cycle' => 'monthly', 'started_at' => now(), 'expires_at' => now()->addMonth(),
        ]);
        $this->assertSame(119.0, PlanPricing::cycleAmount($sub->load('plan')));
    }
}
