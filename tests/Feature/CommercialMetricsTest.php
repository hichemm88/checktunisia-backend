<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscription\CommercialMetrics;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MRR et ARR — un seul calcul, deux échelles.
 *
 * Le risque n'est pas de se tromper une fois : c'est que deux écrans du même
 * back-office finissent par répondre deux chiffres. C'est déjà arrivé sur
 * l'entonnoir des essais. ARR ne doit donc surtout pas être une seconde
 * formule posée à côté du MRR — les deux dérivent du MÊME jeu d'abonnements
 * dédupliqués et du MÊME barème (PlanPricing).
 *
 * Convention retenue, et testée ici :
 *  - abonnement mensuel → prix mensuel × 12 ;
 *  - abonnement annuel  → le prix annuel RÉELLEMENT facturé (11 mois, un mois
 *    offert), pas le mensuel arrondi puis remultiplié — sinon l'ARR d'un
 *    client annuel ne retombe jamais sur le montant de sa facture.
 */
class CommercialMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function planId(string $slug): int
    {
        return (int) SubscriptionPlan::where('slug', $slug)->value('id');
    }

    /** @return array{0: Organization, 1: Hotel} */
    private function org(string $name, string $mode = Organization::BILLING_COMMERCIAL, int $properties = 1): array
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => strtolower(str_replace(' ', '-', $name)).'@test.tn',
            'status' => 'active', 'locale' => 'fr', 'billing_mode' => $mode,
        ]);

        $first = null;
        for ($i = 0; $i < $properties; $i++) {
            $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
            $first ??= $hotel;
        }

        return [$org, $first];
    }

    private function subscribe(Organization $org, string $slug, string $cycle = 'monthly', array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $org->id,
            'plan_id'         => $this->planId($slug),
            'status'          => 'active',
            'billing_cycle'   => $cycle,
            'started_at'      => now()->subDays(10),
            'expires_at'      => now()->addDays(20),
            'auto_renew'      => true,
        ], $attrs));
    }

    private function snapshot(): array
    {
        return app(CommercialMetrics::class)->snapshot();
    }

    // ── Les deux cycles ──────────────────────────────────────────────────────

    public function test_a_monthly_subscription_is_twelve_times_its_month(): void
    {
        [$org] = $this->org('Mensuel');
        $this->subscribe($org, 'essentiel');

        $m = $this->snapshot();

        $this->assertEqualsWithDelta(59.0, $m['mrr'], 0.001);
        $this->assertEqualsWithDelta(708.0, $m['arr'], 0.001);
        $this->assertSame(1, $m['paying_customers']);
    }

    /**
     * Un annuel est vendu 11 mois. L'ARR doit valoir CE montant — c'est celui
     * qui figure sur sa facture. Passer par le mensuel arrondi donnerait
     * 648,996 TND pour une facture de 649,000 : faux, et impossible à
     * expliquer à un comptable.
     */
    public function test_a_yearly_subscription_is_worth_the_price_actually_invoiced(): void
    {
        [$org] = $this->org('Annuel');
        $this->subscribe($org, 'essentiel', 'yearly');

        $m = $this->snapshot();

        $this->assertEqualsWithDelta(649.0, $m['arr'], 0.001, '11 × 59, un mois offert');
        $this->assertEqualsWithDelta(649.0 / 12, $m['mrr'], 0.001, 'le MRR reste la mensualisation du même montant');
    }

    public function test_the_two_scales_stay_consistent_with_each_other(): void
    {
        [$a] = $this->org('Mensuel A');
        [$b] = $this->org('Annuel B');
        $this->subscribe($a, 'pro');
        $this->subscribe($b, 'pro', 'yearly');

        $m = $this->snapshot();

        // Tolérance d'un dinar : l'écart ne peut venir que de l'arrondi de la
        // mensualisation d'un annuel, jamais d'une formule divergente.
        $this->assertEqualsWithDelta($m['mrr'] * 12, $m['arr'], 1.0);
    }

    // ── Le périmètre ─────────────────────────────────────────────────────────

    public function test_an_internal_account_weighs_nothing_in_either_metric(): void
    {
        [$internal] = $this->org('Compte Interne', Organization::BILLING_INTERNAL);
        $this->subscribe($internal, 'hotel');

        $m = $this->snapshot();

        $this->assertSame(0.0, $m['mrr']);
        $this->assertSame(0.0, $m['arr']);
        $this->assertSame(0, $m['paying_customers']);
        $this->assertSame(1, $m['organizations']['internal']);
        $this->assertSame(0, $m['organizations']['commercial']);
    }

    public function test_a_mixed_portfolio_only_counts_the_customers(): void
    {
        [$client] = $this->org('Vrai Client');
        [$internal] = $this->org('Compte Interne', Organization::BILLING_INTERNAL);
        $this->subscribe($client, 'essentiel');
        $this->subscribe($internal, 'hotel');

        $m = $this->snapshot();

        $this->assertEqualsWithDelta(59.0, $m['mrr'], 0.001, 'le compte interne ne s\'ajoute pas');
        $this->assertEqualsWithDelta(708.0, $m['arr'], 0.001);
        $this->assertSame(1, $m['paying_customers']);
        $this->assertSame(1, $m['organizations']['commercial']);
        $this->assertSame(1, $m['organizations']['internal']);
        $this->assertSame(2, $m['organizations']['total']);
    }

    // ── Les pièges de comptage ───────────────────────────────────────────────

    /**
     * Le prix suit le nombre d'établissements (base + suppléments). L'ARR doit
     * donc suivre aussi — un groupe de trois maisons ne vaut pas une maison.
     */
    public function test_the_extra_properties_of_one_organization_are_priced_in(): void
    {
        [$org] = $this->org('Groupe', Organization::BILLING_COMMERCIAL, 3);
        $this->subscribe($org, 'hotel');

        $m = $this->snapshot();

        // 299 de base + 2 établissements supplémentaires × 99.
        $this->assertEqualsWithDelta(497.0, $m['mrr'], 0.001);
        $this->assertEqualsWithDelta(5964.0, $m['arr'], 0.001);
        $this->assertSame(1, $m['paying_customers'], 'trois établissements = UN client');
    }

    /**
     * Un vieil abonnement resté « active » à côté du courant doublerait le
     * chiffre d'affaires du client. On ne compte que le plus récent.
     */
    public function test_an_old_subscription_left_active_never_doubles_a_customer(): void
    {
        [$org] = $this->org('Historique');
        $this->subscribe($org, 'essentiel', 'monthly', ['started_at' => now()->subYear()]);
        $this->subscribe($org, 'pro', 'monthly', ['started_at' => now()->subMonth()]);

        $m = $this->snapshot();

        $this->assertSame(1, $m['paying_customers']);
        $this->assertEqualsWithDelta(119.0, $m['mrr'], 0.001, 'le plan courant, pas l\'ancien');
        $this->assertEqualsWithDelta(1428.0, $m['arr'], 0.001);
    }

    public function test_trials_and_ended_subscriptions_bring_no_revenue(): void
    {
        [$trial] = $this->org('En Essai');
        [$gone]  = $this->org('Parti');
        $this->subscribe($trial, 'pro', 'monthly', ['status' => 'trial', 'metadata' => ['trial' => true]]);
        $this->subscribe($gone, 'pro', 'monthly', ['status' => 'cancelled', 'cancelled_at' => now()->subDay()]);

        $m = $this->snapshot();

        $this->assertSame(0.0, $m['mrr']);
        $this->assertSame(0.0, $m['arr']);
        $this->assertSame(0, $m['paying_customers']);
    }

    public function test_an_empty_platform_reports_zero_not_null(): void
    {
        $m = $this->snapshot();

        $this->assertSame(0.0, $m['mrr']);
        $this->assertSame(0.0, $m['arr']);
        $this->assertSame(0, $m['paying_customers']);
    }

    // ── Les deux écrans admin disent la même chose ───────────────────────────

    public function test_the_dashboard_and_the_kpi_screen_never_disagree(): void
    {
        [$a] = $this->org('Client A');
        [$b] = $this->org('Client B', Organization::BILLING_COMMERCIAL, 2);
        [$i] = $this->org('Interne', Organization::BILLING_INTERNAL);
        $this->subscribe($a, 'essentiel', 'yearly');
        $this->subscribe($b, 'hotel');
        $this->subscribe($i, 'pro');

        $admin = User::factory()->platformAdmin()->create();

        $dashboard = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');
        $kpis      = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')->assertOk()->json('data');

        $this->assertEqualsWithDelta($dashboard['mrr'], $kpis['mrr']['current'], 0.001);
        $this->assertSame($dashboard['paying_customers'], $kpis['arpu']['paying_customers']);
        $this->assertEqualsWithDelta($dashboard['arr'], $kpis['arr']['current'], 0.001);

        // Et la répartition du parc reste lisible sur le tableau de bord.
        $this->assertSame(2, $dashboard['organizations']['commercial']);
        $this->assertSame(1, $dashboard['organizations']['internal']);
        $this->assertSame(3, $dashboard['organizations']['total']);
    }
}
