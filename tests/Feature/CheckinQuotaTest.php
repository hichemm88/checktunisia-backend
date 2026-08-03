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
use App\Services\Subscription\PlanEntitlements;
use App\Services\Subscription\QuotaAlertService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Grille V2 — quota mensuel de check-ins : comptage, calcul des tranches de
 * dépassement (cas limites), alertes uniques par cycle, grandfathering des
 * comptes legacy et clôture mensuelle (facturation + upsell).
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

    private function makeCheckIns(int $count, array $attrs = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            CheckIn::factory()->create(array_merge([
                'hotel_id'   => $this->hotel->id,
                'status'     => 'active',
                'created_by' => $this->creator->id,
            ], $attrs));
        }
    }

    // ── Calcul des tranches (cas limites) ────────────────────────────────────

    public function test_bundle_count_edge_cases(): void
    {
        // quota 100, tranches de 50 : 0 / pile au quota / +1 / +50 / +51
        $this->assertSame(0, CheckinQuota::bundleCount(0, 100, 50));
        $this->assertSame(0, CheckinQuota::bundleCount(100, 100, 50));
        $this->assertSame(1, CheckinQuota::bundleCount(101, 100, 50));
        $this->assertSame(1, CheckinQuota::bundleCount(150, 100, 50));
        $this->assertSame(2, CheckinQuota::bundleCount(151, 100, 50));
    }

    // ── Comptage mensuel ─────────────────────────────────────────────────────

    public function test_used_in_month_excludes_cancelled_and_other_months(): void
    {
        $this->subscribe('essentiel');
        $this->makeCheckIns(3);
        $this->makeCheckIns(1, ['status' => 'cancelled']);
        $this->makeCheckIns(2, ['created_at' => now()->subMonthNoOverflow()]);

        $this->assertSame(3, CheckinQuota::usedInMonth($this->org));
        $this->assertSame(2, CheckinQuota::usedInMonth($this->org, now()->subMonthNoOverflow()));
    }

    // ── Résolution du quota & grandfathering ─────────────────────────────────

    public function test_v2_quotas_and_legacy_overrides(): void
    {
        // Nouvelle grille : Essentiel 100, Pro 600, Hôtel illimité.
        $sub = $this->subscribe('essentiel');
        $this->assertSame(100, CheckinQuota::quota($this->org));

        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id')]);
        $this->assertSame(600, CheckinQuota::quota($this->org->fresh()));

        $sub->update(['plan_id' => SubscriptionPlan::where('slug', 'hotel')->value('id')]);
        $this->assertNull(CheckinQuota::quota($this->org->fresh()));

        // Grandfathering : un ancien Pro reste ILLIMITÉ via l'override figé.
        $sub->update([
            'plan_id'        => SubscriptionPlan::where('slug', 'pro')->value('id'),
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => -1]],
        ]);
        $this->assertNull(CheckinQuota::quota($this->org->fresh()));
        $this->assertFalse(CheckinQuota::overageBillable($sub->fresh()));
    }

    public function test_checkins_are_never_blocked_by_quota(): void
    {
        $this->subscribe('essentiel');
        $this->makeCheckIns(5);

        // Même très au-delà du quota, le garde-fou légal ne lève jamais de 422.
        PlanEntitlements::assertWithinLimit($this->org, 'checkins_per_month', used: 10_000, adding: 1);
        $this->addToAssertionCount(1);
    }

    // ── Alertes 80 % / 100 % (anti-spam par cycle) ───────────────────────────

    public function test_warn_80_sent_once_per_cycle(): void
    {
        Mail::fake();
        $this->subscribe('essentiel');
        User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);
        $this->makeCheckIns(80);

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
        $this->makeCheckIns(100);

        QuotaAlertService::evaluate($this->org);

        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_REACHED)->count());
        $this->assertSame(1, AppNotification::where('type', 'quota_reached')->count());
        Mail::assertSent(SystemMail::class, 1);

        // L'encadré de facturation n'existe que pour un compte facturable.
        $billable = CheckinQuota::status($this->org);
        $this->assertNotSame('', QuotaAlertService::overageNotice($billable, 'fr'));
        $this->assertSame('', QuotaAlertService::overageNotice(array_merge($billable, ['billable' => false]), 'fr'));
    }

    public function test_no_alert_for_unlimited_accounts(): void
    {
        Mail::fake();
        // Ancien Pro grandfathered : illimité — jamais d'alerte, quel que soit le volume.
        $this->subscribe('pro', [
            'is_legacy_plan' => true,
            'metadata'       => ['feature_overrides' => ['checkins_per_month' => -1]],
        ]);
        $this->makeCheckIns(120);

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
        $this->makeCheckIns(100);

        QuotaAlertService::evaluate($this->org);

        $this->assertSame(100, CheckinQuota::quota($this->org));
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_REACHED)->count());
    }

    // ── Clôture mensuelle : dépassements + facturation ───────────────────────

    public function test_close_month_invoices_non_legacy_overage(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $this->subscribe('essentiel');
        // 151 check-ins le mois dernier : 51 au-delà de 100 → 2 tranches de 50 → 20 TND.
        $this->makeCheckIns(151, ['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $this->artisan('quota:close-month')->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame(51, $charge->overage_count);
        $this->assertSame(2, $charge->bundle_count);
        $this->assertSame('20.000', (string) $charge->amount);
        $this->assertSame(OverageCharge::STATUS_INVOICED, $charge->status);
        $this->assertNotNull($charge->invoice_id);
        $this->assertSame('20.000', (string) $charge->invoice->total_amount);
        $this->assertTrue((bool) ($charge->invoice->metadata['overage'] ?? false));

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
        $this->makeCheckIns(130, ['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $this->artisan('quota:close-month')->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame(OverageCharge::STATUS_EXCLUDED_LEGACY, $charge->status);
        $this->assertNull($charge->invoice_id);
        $this->assertSame(0, \App\Models\Invoice::count());
    }

    public function test_close_month_no_charge_at_or_under_quota(): void
    {
        $this->subscribe('essentiel');
        $this->makeCheckIns(100, ['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $this->artisan('quota:close-month')->assertSuccessful();

        $this->assertSame(0, OverageCharge::count());
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
            'bundle_size'     => 50, 'bundle_count' => 1, 'unit_price' => 10, 'amount' => 10,
            'status'          => OverageCharge::STATUS_INVOICED,
        ]);
        // Mois M-1 en dépassement lui aussi.
        $this->makeCheckIns(120, ['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)]);

        $this->artisan('quota:close-month')->assertSuccessful();

        $this->assertNotNull($this->org->fresh()->upsell_flagged_at);
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_UPSELL)->count());

        // Relance : l'email d'upsell ne repart pas.
        $this->artisan('quota:close-month')->assertSuccessful();
        $this->assertSame(1, QuotaAlert::where('threshold', QuotaAlert::THRESHOLD_UPSELL)->count());
    }

    // ── Grille publique & parcours ───────────────────────────────────────────

    public function test_public_plans_expose_v2_grid_without_legacy_multisites(): void
    {
        $response = $this->getJson('/api/v1/public/plans')->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');

        $this->assertSame(['essentiel', 'pro', 'hotel'], $slugs->all());

        $pro = collect($response->json('data'))->firstWhere('slug', 'pro');
        $this->assertSame(600, $pro['features']['checkins_per_month']);
        $this->assertSame('10.000', (string) $pro['overage_price']);
        $this->assertSame(50, $pro['overage_bundle_size']);

        $hotel = collect($response->json('data'))->firstWhere('slug', 'hotel');
        $this->assertSame('99.000', (string) $hotel['extra_property_price']);
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

    public function test_admin_can_migrate_legacy_account_to_v2(): void
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
        $this->makeCheckIns(85);

        // Second hébergeur illimité — ne doit pas apparaître.
        $other      = Organization::create(['name' => 'GRAND HOTEL', 'entity_type' => 'company', 'contact_email' => 'gh@test.tn', 'status' => 'active']);
        Subscription::create([
            'organization_id' => $other->id,
            'plan_id'         => SubscriptionPlan::where('slug', 'hotel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now(), 'expires_at' => now()->addMonth(),
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
