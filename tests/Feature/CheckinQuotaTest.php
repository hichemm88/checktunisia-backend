<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\AppNotification;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\OverageCharge;
use App\Models\PlatformSetting;
use App\Models\QuotaAlert;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscription\CheckinQuota;
use App\Services\Subscription\CheckinUsageRecorder;
use App\Services\Subscription\PlanEntitlements;
use App\Services\Subscription\QuotaAlertService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Grille V3 — quota mensuel de check-ins : comptage par le registre de
 * consommation, dépassement facturé au check-in, alertes uniques par cycle,
 * gel des quotas vendus et clôture mensuelle (facturation + upsell).
 *
 * L'idempotence et les règles de consommation (brouillon, annulation, retry,
 * concurrence, isolation tenant) sont couvertes par CheckinUsageLedgerTest.
 */
class CheckinQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Hotel $hotel;
    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        $this->org = Organization::create([
            'name' => 'DAR OMI TEST', 'entity_type' => 'company',
            'contact_email' => 'dar-omi@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $this->hotel   = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->creator = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function subscribe(string $slug, array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $this->org->id,
            'plan_id'         => SubscriptionPlan::where('slug', $slug)->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subMonths(2),
            'expires_at'      => now()->addMonth(),
        ], $attrs));
    }

    /**
     * Déclare $count fiches : création PUIS finalisation, comme le fait le
     * comptoir. Seule la finalisation consomme du quota — c'est elle qu'on
     * simule ici en posant la consommation dans le registre.
     */
    private function declareCheckIns(int $count, ?\Illuminate\Support\Carbon $at = null, array $attrs = []): void
    {
        $at ??= now();

        for ($i = 0; $i < $count; $i++) {
            $checkIn = CheckIn::factory()->create(array_merge([
                'hotel_id'     => $this->hotel->id,
                'status'       => 'active',
                'created_by'   => $this->creator->id,
                'completed_at' => $at,
                'created_at'   => $at,
            ], $attrs));

            CheckinUsageRecorder::record($checkIn, $at);
        }
    }

    // ── Calcul du dépassement (cas limites) ──────────────────────────────────

    public function test_overage_count_edge_cases_per_unit(): void
    {
        // Grille V3 : tranche de 1 → une tranche = un check-in supplémentaire.
        $this->assertSame(0, CheckinQuota::bundleCount(0, 100, 1));
        $this->assertSame(0, CheckinQuota::bundleCount(100, 100, 1));   // pile au quota
        $this->assertSame(1, CheckinQuota::bundleCount(101, 100, 1));   // premier au-delà
        $this->assertSame(12, CheckinQuota::bundleCount(312, 300, 1));  // exemple du cahier des charges
    }

    public function test_bundle_count_still_supports_lots(): void
    {
        // La formule de tranche reste valable si un pack repasse à un lot > 1.
        $this->assertSame(0, CheckinQuota::bundleCount(100, 100, 50));
        $this->assertSame(1, CheckinQuota::bundleCount(101, 100, 50));
        $this->assertSame(1, CheckinQuota::bundleCount(150, 100, 50));
        $this->assertSame(2, CheckinQuota::bundleCount(151, 100, 50));
    }

    // ── Comptage mensuel ─────────────────────────────────────────────────────

    public function test_used_in_month_counts_declared_checkins_of_that_month_only(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(3);
        $this->declareCheckIns(2, now()->subMonthNoOverflow()->startOfMonth()->addDays(3));

        $this->assertSame(3, CheckinQuota::usedInMonth($this->org));
        $this->assertSame(2, CheckinQuota::usedInMonth($this->org, now()->subMonthNoOverflow()));
    }

    // ── Grille V3 & gel des quotas vendus ────────────────────────────────────

    public function test_v3_quotas_per_plan(): void
    {
        $sub = $this->subscribe('essentiel');
        $this->assertSame(100, CheckinQuota::quota($this->org));

        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id')]);
        $this->assertSame(300, CheckinQuota::quota($this->org->fresh()));

        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'hotel')->value('id')]);
        $this->assertSame(1000, CheckinQuota::quota($this->org->fresh()));
    }

    public function test_frozen_quota_survives_the_grid_change(): void
    {
        // Un compte vendu « Pro 600 » (grille V2) garde 600 : le gel prime sur
        // la valeur du pack, et le dépassement reste facturable au tarif courant.
        $sub = $this->subscribe('pro', [
            'metadata' => ['feature_overrides' => ['checkins_per_month' => 600]],
        ]);

        $this->assertSame(600, CheckinQuota::quota($this->org));
        $this->assertTrue(CheckinQuota::overageBillable($sub));

        // Un compte vendu « illimité » (ancien Grand Flux) n'est jamais en dépassement.
        $sub->update(['metadata' => ['feature_overrides' => ['checkins_per_month' => -1]]]);
        $this->assertNull(CheckinQuota::quota($this->org->fresh()));
    }

    public function test_legacy_account_is_never_billed_for_overage(): void
    {
        $sub = $this->subscribe('essentiel', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => 100]],
        ]);

        $this->assertSame(100, CheckinQuota::quota($this->org));
        $this->assertFalse(CheckinQuota::overageBillable($sub));
    }

    public function test_checkins_are_never_blocked_by_quota(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(5);

        // Même très au-delà du quota, le garde-fou légal ne lève jamais de 422.
        PlanEntitlements::assertWithinLimit($this->org, 'checkins_per_month', used: 10_000, adding: 1);
        $this->addToAssertionCount(1);
    }

    // ── Payload de statut : ce que voit le client ────────────────────────────

    public function test_status_reports_remaining_and_estimated_total_within_quota(): void
    {
        $this->subscribe('pro');
        $this->declareCheckIns(287);

        $status = CheckinQuota::status($this->org);

        $this->assertSame(300, $status['quota']);
        $this->assertSame(287, $status['used']);
        $this->assertSame(13, $status['remaining']);
        $this->assertSame(95, $status['percent']);
        $this->assertSame(0, $status['overage_count']);
        $this->assertSame(0.0, $status['overage_amount']);
        $this->assertSame(119.0, $status['monthly_base']);
        $this->assertSame(119.0, $status['estimated_total']);
    }

    public function test_status_computes_overage_and_estimated_total_beyond_quota(): void
    {
        // L'exemple du cahier des charges : Pro, 312/300 → 12 × 0,400 = 4,800
        // et un total estimé de 123,800 TND.
        $this->subscribe('pro');
        $this->declareCheckIns(312);

        $status = CheckinQuota::status($this->org);

        $this->assertSame(12, $status['overage_count']);
        $this->assertSame(1, $status['bundle_size']);
        $this->assertSame(12, $status['bundle_count']);
        $this->assertSame(0.4, $status['unit_price']);
        $this->assertSame(4.8, $status['overage_amount']);
        $this->assertSame(123.8, $status['estimated_total']);
        $this->assertSame(0, $status['remaining']);
        $this->assertTrue($status['billable']);
    }

    public function test_exactly_at_quota_is_not_an_overage(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(100);

        $status = CheckinQuota::status($this->org);
        $this->assertSame(0, $status['overage_count']);
        $this->assertSame(0.0, $status['overage_amount']);
        $this->assertSame(59.0, $status['estimated_total']);
    }

    public function test_first_checkin_beyond_quota_costs_exactly_one_unit(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(101);

        $status = CheckinQuota::status($this->org);
        $this->assertSame(1, $status['overage_count']);
        $this->assertSame(0.6, $status['overage_amount']);
        $this->assertSame(59.6, $status['estimated_total']);
    }

    public function test_estimated_total_ignores_overage_that_will_never_be_billed(): void
    {
        // Compte grandfathered : le dépassement est calculé (pilotage) mais le
        // total annoncé au client reste celui de son abonnement.
        $this->subscribe('essentiel', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => 100]],
        ]);
        $this->declareCheckIns(150);

        $status = CheckinQuota::status($this->org);
        $this->assertSame(50, $status['overage_count']);
        $this->assertFalse($status['billable']);
        $this->assertSame(59.0, $status['estimated_total']);
    }

    // ── Alertes 80 % / 100 % (anti-spam par cycle) ───────────────────────────

    public function test_warn_80_sent_once_per_cycle(): void
    {
        Mail::fake();
        $this->subscribe('essentiel');
        User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);
        $this->declareCheckIns(80);

        QuotaAlertService::evaluate($this->org);
        QuotaAlertService::evaluate($this->org); // relance le même mois : rien de plus

        $this->assertSame(1, QuotaAlert::where('organization_id', $this->org->id)
            ->where('threshold', QuotaAlert::THRESHOLD_WARN_80)->count());
        Mail::assertSent(SystemMail::class, 1);
        $this->assertSame(1, AppNotification::where('type', 'quota_warning')->count());
    }

    public function test_reached_100_alert_mentions_billing_only_when_billable(): void
    {
        Mail::fake();
        $this->subscribe('essentiel');
        User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);
        $this->declareCheckIns(100);

        QuotaAlertService::evaluate($this->org);

        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_REACHED)->count());
        $this->assertSame(1, AppNotification::where('type', 'quota_reached')->count());
        Mail::assertSent(SystemMail::class, 1);

        // L'encadré de facturation n'existe que pour un compte facturable, et
        // parle de check-ins (tranche de 1), pas de « tranches ».
        $billable = CheckinQuota::status($this->org);
        $notice   = QuotaAlertService::overageNotice($billable, 'fr');
        $this->assertStringContainsString('Chaque check-in supplémentaire', $notice);
        $this->assertStringNotContainsString('tranche', $notice);
        $this->assertSame('', QuotaAlertService::overageNotice(array_merge($billable, ['billable' => false]), 'fr'));
    }

    public function test_overage_notice_still_reads_correctly_for_lots(): void
    {
        $status = ['billable' => true, 'unit_price' => 10.0, 'bundle_size' => 50];
        $this->assertStringContainsString('tranche supplémentaire de 50', QuotaAlertService::overageNotice($status, 'fr'));
    }

    public function test_no_alert_for_unlimited_accounts(): void
    {
        Mail::fake();
        // Ancien Pro grandfathered : illimité — jamais d'alerte, quel que soit le volume.
        $this->subscribe('pro', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => -1]],
        ]);
        $this->declareCheckIns(1200);

        QuotaAlertService::evaluate($this->org);

        $this->assertSame(0, QuotaAlert::count());
        Mail::assertNothingSent();
    }

    public function test_legacy_essentiel_keeps_quota_100_and_gets_alerts(): void
    {
        Mail::fake();
        $this->subscribe('essentiel', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => 100]],
        ]);
        $this->declareCheckIns(100);

        QuotaAlertService::evaluate($this->org);

        $this->assertSame(100, CheckinQuota::quota($this->org));
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_REACHED)->count());
    }

    // ── Clôture mensuelle : dépassements + facturation ───────────────────────

    private function lastMonth(): \Illuminate\Support\Carbon
    {
        return now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
    }

    public function test_close_month_invoices_non_legacy_overage_per_unit(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $this->subscribe('pro');
        // 312 check-ins le mois dernier : 12 au-delà de 300 → 12 × 0,400 = 4,800 TND.
        $this->declareCheckIns(312, $this->lastMonth());

        $this->artisan('quota:close-month')->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame(12, $charge->overage_count);
        $this->assertSame(1, $charge->bundle_size);
        $this->assertSame(12, $charge->bundle_count);
        $this->assertSame('4.800', (string) $charge->amount);
        $this->assertSame(OverageCharge::STATUS_INVOICED, $charge->status);
        $this->assertNotNull($charge->invoice_id);
        $this->assertSame('4.800', (string) $charge->invoice->total_amount);
        $this->assertTrue((bool) ($charge->invoice->metadata['overage'] ?? false));

        // La facture s'explique sans les CGV : volume, quota inclus, prix unitaire.
        $this->assertStringContainsString('312 check-ins déclarés', $charge->invoice->notes);
        $this->assertStringContainsString('quota de 300 inclus', $charge->invoice->notes);
        $this->assertStringContainsString('12 check-in(s) supplémentaire(s) × 0,4 TND', $charge->invoice->notes);

        // L'admin peut retrouver le pourquoi dans l'historique de l'abonnement.
        $this->assertDatabaseHas('subscription_events', ['event_type' => 'overage_invoiced']);

        // Idempotent : une relance ne refacture pas.
        $this->artisan('quota:close-month')->assertSuccessful();
        $this->assertSame(1, OverageCharge::count());
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_close_month_never_invoices_legacy_accounts(): void
    {
        $this->subscribe('essentiel', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => 100]],
        ]);
        $this->declareCheckIns(130, $this->lastMonth());

        $this->artisan('quota:close-month')->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame(OverageCharge::STATUS_EXCLUDED_LEGACY, $charge->status);
        $this->assertNull($charge->invoice_id);
        $this->assertSame(0, \App\Models\Invoice::count());
    }

    public function test_close_month_no_charge_at_or_under_quota(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(100, $this->lastMonth());

        $this->artisan('quota:close-month')->assertSuccessful();

        $this->assertSame(0, OverageCharge::count());
    }

    public function test_closed_period_is_immutable_even_if_usage_changes_later(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $this->subscribe('essentiel');
        $this->declareCheckIns(110, $this->lastMonth());

        $this->artisan('quota:close-month')->assertSuccessful();
        $charge = OverageCharge::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame('6.000', (string) $charge->amount); // 10 × 0,600

        // Le mois clôturé continue de vivre (nouvelles déclarations tardives) :
        // la facture déjà émise ne bouge pas d'un millime.
        $this->declareCheckIns(20, $this->lastMonth());
        $this->artisan('quota:close-month')->assertSuccessful();

        $this->assertSame('6.000', (string) $charge->fresh()->amount);
        $this->assertSame(10, $charge->fresh()->overage_count);
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_upsell_flagged_after_two_consecutive_overage_months(): void
    {
        Mail::fake();
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $sub = $this->subscribe('essentiel');

        // Mois M-2 déjà clôturé en dépassement.
        OverageCharge::create([
            'organization_id' => $this->org->id, 'subscription_id' => $sub->id,
            'period'          => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
            'checkins_count'  => 120, 'quota' => 100, 'overage_count' => 20,
            'bundle_size'     => 1, 'bundle_count' => 20, 'unit_price' => 0.6, 'amount' => 12,
            'status'          => OverageCharge::STATUS_INVOICED,
        ]);
        // Mois M-1 en dépassement lui aussi.
        $this->declareCheckIns(120, $this->lastMonth());

        $this->artisan('quota:close-month')->assertSuccessful();

        $this->assertNotNull($this->org->fresh()->upsell_flagged_at);
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_UPSELL)->count());

        // Relance : l'email d'upsell ne repart pas.
        $this->artisan('quota:close-month')->assertSuccessful();
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_UPSELL)->count());
    }

    // ── Grille publique & parcours ───────────────────────────────────────────

    public function test_public_plans_expose_v3_grid_without_legacy_multisites(): void
    {
        $response = $this->getJson('/api/v1/public/plans')->assertOk();
        $plans = collect($response->json('data'));

        $this->assertSame(['essentiel', 'pro', 'hotel'], $plans->pluck('slug')->all());

        // Le site marketing et le produit lisent la MÊME configuration : ce qui
        // est vérifié ici est exactement ce que la landing affiche.
        $expected = [
            'essentiel' => ['price' => '59.000',  'quota' => 100,  'overage' => '0.600'],
            'pro'       => ['price' => '119.000', 'quota' => 300,  'overage' => '0.400'],
            'hotel'     => ['price' => '299.000', 'quota' => 1000, 'overage' => '0.250'],
        ];

        foreach ($expected as $slug => $want) {
            $plan = $plans->firstWhere('slug', $slug);
            $this->assertSame($want['price'], (string) $plan['price_monthly'], "prix {$slug}");
            $this->assertSame($want['quota'], $plan['features']['checkins_per_month'], "quota {$slug}");
            $this->assertSame($want['overage'], (string) $plan['overage_price'], "dépassement {$slug}");
            $this->assertSame(1, $plan['overage_bundle_size'], "tranche {$slug}");
        }

        $this->assertSame('99.000', (string) $plans->firstWhere('slug', 'hotel')['extra_property_price']);
    }

    public function test_marketing_bullets_announce_the_same_quota_as_the_configuration(): void
    {
        // Une carte marketing qui promet un quota différent de celui appliqué
        // est un litige commercial : les deux doivent rester alignés.
        foreach (['essentiel' => '100', 'pro' => '300', 'hotel' => '1 000'] as $slug => $expected) {
            $plan    = SubscriptionPlan::where('slug', $slug)->firstOrFail();
            $bullets = collect($plan->marketing['bullets'] ?? [])->pluck('text.fr')->implode(' | ');

            $this->assertStringContainsString("{$expected} check-ins inclus", $bullets, "carte {$slug}");
        }
    }

    public function test_legacy_plan_is_not_subscribable(): void
    {
        $this->postJson('/api/v1/public/register', [
            'entity_type' => 'company', 'org_name' => 'Test SARL',
            'first_name' => 'Ali', 'last_name' => 'Test', 'email' => 'ali@test.tn',
            'password' => 'Password1!Secure', 'password_confirmation' => 'Password1!Secure',
            'plan_slug' => 'multi-sites',
        ])->assertStatus(404);
    }

    public function test_upgrade_request_records_event(): void
    {
        $sub  = $this->subscribe('essentiel');
        $user = User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);

        $this->actingAs($user)->postJson('/api/v1/hotel/subscription/upgrade-request', [
            'plan_slug' => 'pro',
        ])->assertOk()->assertJsonPath('data.status', 'requested');

        $this->assertDatabaseHas('subscription_events', [
            'subscription_id' => $sub->id,
            'event_type'      => 'upgrade_requested',
        ]);

        // Un plan legacy n'est pas une cible valide.
        $this->actingAs($user)->postJson('/api/v1/hotel/subscription/upgrade-request', [
            'plan_slug' => 'multi-sites',
        ])->assertStatus(422);
    }

    public function test_plan_change_applies_the_new_quota_without_touching_usage(): void
    {
        // Upgrade en cours de mois : le quota suit immédiatement, la
        // consommation déjà enregistrée n'est ni perdue ni recomptée.
        $sub = $this->subscribe('essentiel');
        $this->declareCheckIns(120);

        $this->assertSame(20, CheckinQuota::status($this->org)['overage_count']);

        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id')]);
        $status = CheckinQuota::status($this->org->fresh());

        $this->assertSame(120, $status['used']);
        $this->assertSame(300, $status['quota']);
        $this->assertSame(0, $status['overage_count']);

        // Downgrade : le dépassement réapparaît, toujours sans recompter l'usage.
        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'essentiel')->value('id')]);
        $status = CheckinQuota::status($this->org->fresh());
        $this->assertSame(120, $status['used']);
        $this->assertSame(20, $status['overage_count']);
    }

    public function test_cancelled_subscription_keeps_its_usage_and_invoices(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $sub = $this->subscribe('essentiel');
        $this->declareCheckIns(110, $this->lastMonth());
        $this->artisan('quota:close-month')->assertSuccessful();

        $sub->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        // Ni l'usage ni la facturation historique ne disparaissent.
        $this->assertSame(110, CheckinQuota::usedInMonth($this->org, now()->subMonthNoOverflow()));
        $this->assertSame(1, OverageCharge::where('organization_id', $this->org->id)->count());
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_admin_can_migrate_legacy_account_to_current_grid(): void
    {
        $sub = $this->subscribe('multi-sites', [
            'is_legacy_plan' => true,
            'custom_price'   => 179,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => -1, 'max_users' => 12]],
        ]);
        $admin  = User::factory()->platformAdmin()->create();
        $target = SubscriptionPlan::where('slug', 'hotel')->first();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/hosts/{$this->org->id}/subscriptions/{$sub->id}/migrate-to-v2", [
                'plan_id' => $target->id,
            ])->assertOk()
            ->assertJsonPath('data.plan.slug', 'hotel');

        $fresh = $sub->fresh();
        $this->assertFalse($fresh->is_legacy_plan);
        $this->assertNull($fresh->custom_price);
        $overrides = $fresh->metadata['feature_overrides'];
        $this->assertArrayNotHasKey('checkins_per_month', $overrides);
        // Les autres overrides négociés sont conservés.
        $this->assertSame(12, $overrides['max_users']);
        // Et le compte bascule bien sur le quota du pack courant.
        $this->assertSame(1000, CheckinQuota::quota($this->org->fresh()));

        // Un plan non public n'est pas une cible valide.
        $multisites = SubscriptionPlan::where('slug', 'multi-sites')->first();
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/hosts/{$this->org->id}/subscriptions/{$sub->id}/migrate-to-v2", [
                'plan_id' => $multisites->id,
            ])->assertStatus(422);
    }

    public function test_admin_quota_dashboard_lists_only_finite_quota_accounts(): void
    {
        $this->subscribe('essentiel');
        $this->declareCheckIns(85);

        // Second hébergeur illimité (gel « vendu illimité ») — ne doit pas apparaître.
        $other = Organization::create(['name' => 'GRAND HOTEL', 'entity_type' => 'company', 'contact_email' => 'gh@test.tn', 'status' => 'active']);
        Subscription::create([
            'organization_id' => $other->id,
            'plan_id'         => SubscriptionPlan::where('slug', 'hotel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now(), 'expires_at' => now()->addMonth(),
            'metadata'        => ['feature_overrides' => ['checkins_per_month' => -1]],
        ]);

        $admin = User::factory()->platformAdmin()->create();
        $data  = $this->actingAs($admin)->getJson('/api/v1/admin/quotas?filter=warning')
            ->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('DAR OMI TEST', $data[0]['name']);
        $this->assertSame(85, $data[0]['used']);
        $this->assertSame(100, $data[0]['quota']);
    }
}
