<?php

namespace Tests\Feature;

use App\Models\CheckinUsageEvent;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Subscription\CheckinQuota;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Gestion de l'abonnement en self-service.
 *
 * Le fil conducteur : un hôtelier doit pouvoir mener seul toutes les
 * opérations normales — voir, comparer, changer, résilier, reprendre — sans
 * qu'un administrateur ait à intervenir, et sans qu'un double clic, un rejeu
 * réseau ou un paiement raté ne puisse produire une facture de trop ou un
 * plan activé sans avoir été payé.
 */
class SubscriptionSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Hotel $hotel;
    private User $owner;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);

        [$this->org, $this->hotel, $this->owner] = $this->makeTenant('DAR OMI', 'dar-omi@test.tn');
        $this->sub = $this->subscribeOrg($this->org, 'essentiel');
    }

    /** @return array{0: Organization, 1: Hotel, 2: User} */
    private function makeTenant(string $name, string $email): array
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => $email, 'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $org->id, 'role_org' => 'owner',
        ]);

        return [$org, $hotel, $owner];
    }

    private function subscribeOrg(Organization $org, string $slug, array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $org->id,
            'plan_id'         => SubscriptionPlan::where('slug', $slug)->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(10),
            'expires_at'      => now()->addDays(20),
            'auto_renew'      => true,
        ], $attrs));
    }

    private function planId(string $slug): int
    {
        return (int) SubscriptionPlan::where('slug', $slug)->value('id');
    }

    /** Règle la facture par le circuit réel : c'est `handleInvoicePaid` que tous les canaux appellent. */
    private function payInvoice(Invoice $invoice): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'flouci']);
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);
    }

    private function changeTo(string $slug, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/change', array_merge([
            'plan_id'         => $this->planId($slug),
            'idempotency_key' => 'intent-'.$slug.'-0001',
        ], $payload));
    }

    // ── Lecture ──────────────────────────────────────────────────────────────

    public function test_the_client_sees_its_subscription_plan_usage_and_overage(): void
    {
        $data = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data');

        $this->assertSame('essentiel', $data['plan']['slug']);
        $this->assertSame('active', $data['status']);
        $this->assertSame(100, $data['quota']['quota']);
        $this->assertEquals(59, $data['quota']['estimated_total']);
        $this->assertNull($data['pending_change']);
        $this->assertFalse($data['cancellation']['scheduled']);
    }

    public function test_plans_are_listed_with_a_ready_made_simulation_for_this_client(): void
    {
        $data = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription/plans')
            ->assertOk()->json('data');

        $this->assertSame(['essentiel', 'pro', 'hotel'], collect($data['plans'])->pluck('slug')->all());

        $current = collect($data['plans'])->firstWhere('slug', 'essentiel');
        $this->assertTrue($current['is_current']);
        $this->assertNull($current['change']); // pas de simulation vers son propre plan

        // Chaque autre offre arrive déjà chiffrée pour ce client.
        $pro = collect($data['plans'])->firstWhere('slug', 'pro');
        $this->assertSame('upgrade', $pro['change']['kind']);
        $this->assertSame(300, $pro['change']['quota_to']);
        $this->assertEquals(0.4, $pro['change']['overage_unit_price_to']);
        $this->assertEquals(119, $pro['change']['next_renewal_amount']);
        $this->assertTrue($pro['change']['allowed']);
    }

    public function test_a_receptionist_can_read_the_subscription_but_never_change_it(): void
    {
        $recept = User::factory()->receptionist($this->hotel)->create(['organization_id' => $this->org->id]);

        $this->actingAs($recept)->getJson('/api/v1/hotel/subscription')->assertOk();
        $this->actingAs($recept)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id' => $this->planId('pro'), 'idempotency_key' => 'recept-attempt-1',
        ])->assertForbidden();
        $this->actingAs($recept)->postJson('/api/v1/hotel/subscription/cancel')->assertForbidden();

        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
    }

    public function test_an_org_admin_who_is_not_owner_cannot_change_the_plan(): void
    {
        $admin = User::factory()->hotelAdmin($this->hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'admin',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id' => $this->planId('pro'), 'idempotency_key' => 'admin-attempt-1',
        ])->assertForbidden();
    }

    // ── Upgrade ──────────────────────────────────────────────────────────────

    public function test_an_upgrade_bills_before_it_changes_anything(): void
    {
        $data = $this->changeTo('pro')->assertCreated()->json('data');

        $this->assertSame('upgrade', $data['kind']);
        $this->assertSame(SubscriptionPlanChange::STATUS_PENDING_PAYMENT, $data['status']);
        $this->assertNotNull($data['invoice']);

        // Le plan et le quota n'ont PAS bougé : rien n'est offert avant paiement.
        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
        $this->assertSame(100, CheckinQuota::quota($this->org->fresh()));
    }

    public function test_the_upgrade_invoice_credits_the_unused_part_of_the_paid_period(): void
    {
        // 20 jours restants sur une période de ~30 : le client ne repaie pas
        // ce qu'il a déjà réglé et pas consommé.
        $data = $this->changeTo('pro')->assertCreated()->json('data');

        $credit = (float) $data['credit_applied'];
        $this->assertGreaterThan(0, $credit);
        $this->assertLessThan(59, $credit); // jamais plus que la période en cours

        // Facture = prix plein du nouveau plan, remise portée explicitement.
        $invoice = Invoice::findOrFail($data['invoice']['id']);
        $this->assertEquals(119, (float) $invoice->amount);
        $this->assertEquals($credit, (float) $invoice->discount_amount);
        $this->assertEquals(round(119 - $credit, 3), (float) $invoice->total_amount);
        $this->assertEquals(round(119 - $credit, 3), (float) $data['amount_due']);
    }

    public function test_a_paid_upgrade_switches_the_plan_and_opens_a_full_new_period(): void
    {
        Mail::fake();
        $data = $this->changeTo('pro')->assertCreated()->json('data');

        $this->payInvoice(Invoice::findOrFail($data['invoice']['id']));

        $fresh = $this->sub->fresh();
        $this->assertSame($this->planId('pro'), $fresh->plan_id);
        $this->assertSame(300, CheckinQuota::quota($this->org->fresh()));
        // Nouvelle période complète — celle que le client vient de régler.
        $this->assertEqualsWithDelta(now()->addMonthNoOverflow()->timestamp, $fresh->expires_at->timestamp, 120);
        $this->assertSame(SubscriptionPlanChange::STATUS_APPLIED, SubscriptionPlanChange::first()->status);
    }

    public function test_an_unpaid_upgrade_never_activates_the_plan(): void
    {
        $this->changeTo('pro')->assertCreated();

        // Le temps passe, la facture reste impayée : rien ne bascule.
        $this->artisan('invoices:dunning')->assertSuccessful();

        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
        $this->assertSame(SubscriptionPlanChange::STATUS_PENDING_PAYMENT, SubscriptionPlanChange::first()->status);
    }

    public function test_a_failed_payment_leaves_the_plan_untouched_and_the_client_can_retry(): void
    {
        $data    = $this->changeTo('pro')->assertCreated()->json('data');
        $invoice = Invoice::findOrFail($data['invoice']['id']);

        // Échec : la facture reste due, aucune facture fantôme n'est réputée payée.
        \App\Models\Payment::create([
            'invoice_id' => $invoice->id, 'provider' => 'flouci', 'status' => 'failed',
            'amount' => $invoice->total_amount, 'currency' => 'TND',
        ]);

        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
        $this->assertNotSame('paid', $invoice->fresh()->status);

        // Nouvelle tentative sur LA MÊME facture : elle aboutit.
        $this->payInvoice($invoice->fresh());
        $this->assertSame($this->planId('pro'), $this->sub->fresh()->plan_id);
        $this->assertSame(1, Invoice::count(), 'un retry ne crée pas une seconde facture');
    }

    // ── Downgrade ────────────────────────────────────────────────────────────

    public function test_a_downgrade_is_scheduled_and_changes_nothing_today(): void
    {
        $this->sub->update(['plan_id' => $this->planId('pro')]);

        $data = $this->changeTo('essentiel')->assertCreated()->json('data');

        $this->assertSame('downgrade', $data['kind']);
        $this->assertSame(SubscriptionPlanChange::STATUS_SCHEDULED, $data['status']);
        $this->assertEquals(0, $data['amount_due'], 'un downgrade ne facture rien');
        $this->assertSame(0, Invoice::count());

        // Le client garde ce qu'il a payé jusqu'au bout.
        $this->assertSame($this->planId('pro'), $this->sub->fresh()->plan_id);
        $this->assertSame(300, CheckinQuota::quota($this->org->fresh()));
        $this->assertSame(
            $this->sub->expires_at->toDateString(),
            \Illuminate\Support\Carbon::parse($data['effective_at'])->toDateString(),
        );
    }

    public function test_a_scheduled_downgrade_applies_only_once_the_paid_period_is_over(): void
    {
        Mail::fake();
        $this->sub->update(['plan_id' => $this->planId('pro')]);
        $this->changeTo('essentiel')->assertCreated();

        // Avant l'échéance : rien ne bouge.
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();
        $this->assertSame($this->planId('pro'), $this->sub->fresh()->plan_id);

        // Après l'échéance : le nouveau plan prend effet.
        $this->travelTo(now()->addDays(21));
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();

        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
        $this->assertSame(SubscriptionPlanChange::STATUS_APPLIED, SubscriptionPlanChange::first()->status);
    }

    public function test_downgrading_below_current_usage_keeps_every_declared_checkin(): void
    {
        // Pro à 312/300 qui repasse en Essentiel (100 inclus) : l'historique
        // ne se réécrit pas, et le nouveau quota ne vaut qu'à partir du
        // cycle suivant.
        $this->sub->update(['plan_id' => $this->planId('pro')]);
        $this->declare(312);

        $preview = $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/preview-change', [
            'plan_id' => $this->planId('essentiel'),
        ])->assertOk()->json('data');

        $this->assertTrue($preview['usage_exceeds_new_quota']);
        $this->assertSame(312, $preview['usage']);
        $this->assertSame(100, $preview['quota_to']);

        $this->changeTo('essentiel')->assertCreated();

        // Ni les consommations ni le dépassement en cours ne sont touchés.
        $this->assertSame(312, CheckinQuota::usedInMonth($this->org));
        $this->assertSame(312, CheckinUsageEvent::count());
        $status = CheckinQuota::status($this->org->fresh());
        $this->assertSame(300, $status['quota']);
        $this->assertSame(12, $status['overage_count']);
    }

    /** Déclare $n check-ins (consommations réelles du mois en cours). */
    private function declare(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $checkIn = \App\Models\CheckIn::factory()->create([
                'hotel_id' => $this->hotel->id, 'status' => 'active',
                'created_by' => $this->owner->id, 'completed_at' => now(),
            ]);
            \App\Services\Subscription\CheckinUsageRecorder::record($checkIn);
        }
    }

    // ── Idempotence ──────────────────────────────────────────────────────────

    public function test_double_click_produces_one_change_and_one_invoice(): void
    {
        $first  = $this->changeTo('pro')->assertCreated()->json('data');
        $second = $this->changeTo('pro')->assertCreated()->json('data');

        $this->assertSame($first['id'], $second['id'], 'le rejeu retombe sur la même demande');
        $this->assertSame(1, SubscriptionPlanChange::count());
        $this->assertSame(1, Invoice::count(), 'jamais deux factures pour une seule intention');
    }

    public function test_two_tabs_with_different_intents_cannot_stack_two_changes(): void
    {
        $this->changeTo('pro', ['idempotency_key' => 'tab-one-key'])->assertCreated();

        $this->changeTo('hotel', ['idempotency_key' => 'tab-two-key'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PLAN_CHANGE_ALREADY_IN_PROGRESS');

        $this->assertSame(1, SubscriptionPlanChange::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_same_key_from_another_tenant_never_collides(): void
    {
        [$otherOrg, , $otherOwner] = $this->makeTenant('AUTRE MAISON', 'autre@test.tn');
        $this->subscribeOrg($otherOrg, 'essentiel');

        $this->changeTo('pro', ['idempotency_key' => 'shared-key-1'])->assertCreated();
        $this->actingAs($otherOwner)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id' => $this->planId('pro'), 'idempotency_key' => 'shared-key-1',
        ])->assertCreated();

        // Deux demandes distinctes, une par organisation.
        $this->assertSame(2, SubscriptionPlanChange::count());
        $this->assertSame(2, SubscriptionPlanChange::distinct('organization_id')->count('organization_id'));
    }

    public function test_replaying_the_payment_confirmation_never_applies_the_upgrade_twice(): void
    {
        Mail::fake();
        $data    = $this->changeTo('pro')->assertCreated()->json('data');
        $invoice = Invoice::findOrFail($data['invoice']['id']);

        $this->payInvoice($invoice);
        $expiresAfterFirst = $this->sub->fresh()->expires_at;

        // Webhook répété / double vérification / job relancé.
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);

        $this->assertSame($this->planId('pro'), $this->sub->fresh()->plan_id);
        $this->assertSame(
            $expiresAfterFirst->toIso8601String(),
            $this->sub->fresh()->expires_at->toIso8601String(),
            'la période ne se prolonge pas à chaque rejeu',
        );
        $this->assertSame(1, \App\Models\Payment::where('status', 'completed')->count());
    }

    public function test_running_the_scheduler_twice_applies_a_downgrade_only_once(): void
    {
        Mail::fake();
        $this->sub->update(['plan_id' => $this->planId('pro')]);
        $this->changeTo('essentiel')->assertCreated();
        $this->travelTo(now()->addDays(21));

        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();

        $this->assertSame(1, SubscriptionPlanChange::where('status', SubscriptionPlanChange::STATUS_APPLIED)->count());
        $this->assertSame(1, \App\Models\SubscriptionEvent::where('event_type', 'downgrade_applied')->count());
    }

    public function test_a_client_can_cancel_a_pending_change_and_start_another(): void
    {
        $data = $this->changeTo('pro')->assertCreated()->json('data');

        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/change/cancel')->assertOk();

        // La facture d'upgrade n'a plus d'objet : elle ne partira pas en relance.
        $this->assertSame('void', Invoice::find($data['invoice']['id'])->status);
        $this->assertSame(SubscriptionPlanChange::STATUS_CANCELLED, SubscriptionPlanChange::first()->status);

        // La voie est libre pour une autre demande.
        $this->changeTo('hotel', ['idempotency_key' => 'second-intent'])->assertCreated();
    }

    // ── Résiliation / reprise ────────────────────────────────────────────────

    public function test_cancelling_stops_the_renewal_without_cutting_anything(): void
    {
        Mail::fake();
        $this->declare(5);

        $data = $this->actingAs($this->owner)
            ->postJson('/api/v1/hotel/subscription/cancel', ['reason' => 'saison terminée'])
            ->assertOk()->json('data');

        $fresh = $this->sub->fresh();
        $this->assertFalse($fresh->auto_renew);
        $this->assertNotNull($fresh->cancellation_requested_at);
        // L'accès reste ouvert jusqu'au terme payé, le statut ne change pas.
        $this->assertSame('active', $fresh->status);
        $this->assertSame($fresh->expires_at->toIso8601String(), \Illuminate\Support\Carbon::parse($data['ends_at'])->toIso8601String());

        // Rien n'est supprimé.
        $this->assertSame(5, CheckinQuota::usedInMonth($this->org));
        $this->assertSame(5, CheckinUsageEvent::count());
    }

    public function test_a_cancelled_subscription_stops_generating_renewal_invoices(): void
    {
        Mail::fake();
        $this->sub->update(['expires_at' => now()->addDays(3)]);

        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();
        $this->artisan('invoices:generate-due')->assertSuccessful();

        $this->assertSame(0, Invoice::count());
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();

        $this->assertSame(1, \App\Models\SubscriptionEvent::where('event_type', 'renewal_cancelled')->count());
    }

    public function test_the_client_can_restore_the_renewal_itself(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();

        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/reactivate')->assertOk();

        $fresh = $this->sub->fresh();
        $this->assertTrue($fresh->auto_renew);
        $this->assertNull($fresh->cancellation_requested_at);
        $this->assertFalse($fresh->isCancellationScheduled());
    }

    // ── Conditions historiques ───────────────────────────────────────────────

    private function makeHistoricClient(): void
    {
        // Profil KASBAHOST : quota gelé illimité sur le pack Grand Flux.
        $this->sub->update([
            'plan_id'  => $this->planId('hotel'),
            'metadata' => ['feature_overrides' => ['checkins_per_month' => -1]],
        ]);
    }

    public function test_a_historic_client_is_warned_of_what_it_would_lose(): void
    {
        $this->makeHistoricClient();

        $preview = $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/preview-change', [
            'plan_id' => $this->planId('pro'),
        ])->assertOk()->json('data');

        $this->assertTrue($preview['loses_historic_conditions']);
        $this->assertSame('illimité', $preview['historic_conditions']['checkins_label']);
        $this->assertSame(300, $preview['quota_to']);
    }

    public function test_a_historic_client_is_never_downgraded_on_a_single_click(): void
    {
        $this->makeHistoricClient();

        $this->changeTo('pro')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'HISTORIC_CONDITIONS_CONFIRMATION_REQUIRED');

        // Conditions intactes tant que le client n'a pas confirmé.
        $this->assertNull(CheckinQuota::quota($this->org->fresh()));
        $this->assertSame(0, SubscriptionPlanChange::count());
    }

    public function test_historic_conditions_survive_until_a_downgrade_is_actually_applied(): void
    {
        Mail::fake();
        // Scénario réel : un compte au quota gelé illimité (profil KASBAHOST)
        // descend volontairement vers Professionnel. C'est un downgrade — il
        // est programmé, et l'illimité court jusqu'au terme payé.
        $this->makeHistoricClient();

        $data = $this->changeTo('pro', ['accept_conditions_change' => true])->assertCreated()->json('data');
        $this->assertSame('downgrade', $data['kind']);

        // Demande acceptée mais pas encore échue : le client garde son illimité.
        $this->assertNull(CheckinQuota::quota($this->org->fresh()));

        $this->travelTo(now()->addDays(21));
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();

        // Le gel n'est levé qu'à l'application effective, et seulement parce
        // que le client l'avait explicitement accepté.
        $fresh = $this->sub->fresh();
        $this->assertSame(300, CheckinQuota::quota($this->org->fresh()));
        $this->assertArrayNotHasKey('checkins_per_month', (array) ($fresh->metadata['feature_overrides'] ?? []));
        $this->assertFalse($fresh->is_legacy_plan);
    }

    public function test_a_historic_client_upgrading_keeps_its_conditions_until_it_pays(): void
    {
        Mail::fake();
        // Quota gelé bas sur Essentiel : monter vers Pro est un upgrade, donc
        // payant — et les conditions historiques tiennent jusqu'au paiement.
        $this->sub->update(['metadata' => ['feature_overrides' => ['checkins_per_month' => 500]]]);

        $data = $this->changeTo('pro', ['accept_conditions_change' => true])->assertCreated()->json('data');
        $this->assertSame('upgrade', $data['kind']);
        $this->assertSame(500, CheckinQuota::quota($this->org->fresh()));

        $this->payInvoice(Invoice::findOrFail($data['invoice']['id']));

        $fresh = $this->sub->fresh();
        $this->assertSame(300, CheckinQuota::quota($this->org->fresh()));
        $this->assertArrayNotHasKey('checkins_per_month', (array) ($fresh->metadata['feature_overrides'] ?? []));
    }

    public function test_a_negotiated_price_is_flagged_and_not_carried_over(): void
    {
        $this->sub->update(['custom_price' => 39]);

        $preview = $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/preview-change', [
            'plan_id' => $this->planId('pro'),
        ])->assertOk()->json('data');

        $this->assertTrue($preview['loses_historic_conditions']);
        $this->assertEquals(39, $preview['historic_conditions']['custom_price']);
        // Le nouveau plan est facturé à son tarif public, pas au tarif négocié.
        $this->assertEquals(119, $preview['next_renewal_amount']);
    }

    // ── Isolation tenant & intégrité des montants ────────────────────────────

    public function test_a_tenant_never_sees_or_touches_another_subscription(): void
    {
        [$otherOrg, , $otherOwner] = $this->makeTenant('AUTRE MAISON', 'autre@test.tn');
        $otherSub = $this->subscribeOrg($otherOrg, 'hotel');

        $data = $this->actingAs($otherOwner)->getJson('/api/v1/hotel/subscription')->assertOk()->json('data');
        $this->assertSame('hotel', $data['plan']['slug']);
        $this->assertNotSame($this->sub->id, $data['id']);

        // Le voisin change SON plan ; celui d'à côté est intact.
        $this->actingAs($otherOwner)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id' => $this->planId('pro'), 'idempotency_key' => 'neighbour-1',
        ])->assertCreated();

        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
        $this->assertSame($this->planId('hotel'), $otherSub->fresh()->plan_id);
        $this->assertSame(0, Invoice::whereHas('subscription', fn ($q) => $q->where('organization_id', $this->org->id))->count());
    }

    public function test_the_client_cannot_impose_a_price_a_quota_or_a_date(): void
    {
        // Tout ce que le client envoie en plus est ignoré : les montants sont
        // recalculés côté serveur à partir du plan réellement applicable.
        $data = $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id'             => $this->planId('pro'),
            'idempotency_key'     => 'forged-1',
            'amount_due'          => 0,
            'credit_applied'      => 999,
            'next_renewal_amount' => 1,
            'effective_at'        => now()->addYears(5)->toIso8601String(),
            'status'              => 'applied',
        ])->assertCreated()->json('data');

        $change = SubscriptionPlanChange::findOrFail($data['id']);
        $this->assertGreaterThan(0, (float) $change->amount_due);
        $this->assertEquals(119, (float) $change->next_renewal_amount);
        $this->assertSame(SubscriptionPlanChange::STATUS_PENDING_PAYMENT, $change->status);
        $this->assertSame($this->planId('essentiel'), $this->sub->fresh()->plan_id);
    }

    public function test_a_plan_outside_the_public_grid_cannot_be_chosen(): void
    {
        $this->changeTo('multi-sites', ['idempotency_key' => 'legacy-target-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PLAN_CHANGE_NOT_ALLOWED');
    }

    public function test_changing_to_the_current_plan_is_refused(): void
    {
        $this->changeTo('essentiel', ['idempotency_key' => 'same-plan-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PLAN_CHANGE_NOT_ALLOWED');
    }

    // ── Historique & factures ────────────────────────────────────────────────

    public function test_the_history_explains_every_move_of_the_subscription(): void
    {
        Mail::fake();
        $data = $this->changeTo('pro')->assertCreated()->json('data');
        $this->payInvoice(Invoice::findOrFail($data['invoice']['id']));
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();

        $history = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription/history')
            ->assertOk()->json('data');

        $types = collect($history)->pluck('event_type');
        $this->assertTrue($types->contains('upgrade_requested'));
        $this->assertTrue($types->contains('upgrade_applied'));
        $this->assertTrue($types->contains('renewal_cancelled'));

        $applied = collect($history)->firstWhere('event_type', 'upgrade_applied');
        $this->assertSame('Essentiel', $applied['previous_plan']);
        $this->assertSame('Pro', $applied['new_plan']);
    }

    public function test_the_client_sees_its_own_invoices_and_only_those(): void
    {
        [$otherOrg, , $otherOwner] = $this->makeTenant('AUTRE MAISON', 'autre@test.tn');
        $this->subscribeOrg($otherOrg, 'essentiel');

        $this->changeTo('pro')->assertCreated();

        $mine = $this->actingAs($this->owner)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data');
        $this->assertCount(1, $mine);

        $theirs = $this->actingAs($otherOwner)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data');
        $this->assertCount(0, $theirs);
    }

    // ── Activation : régler la formule qu'on a déjà ──────────────────────────
    //
    // Le cas le PLUS courant du self-service, et le seul qui n'était couvert
    // nulle part : un essai (ou un compte retombé) veut payer le plan sur
    // lequel il est déjà. `PlanChangeService::eligibility` l'autorise
    // explicitement — encore faut-il que l'écran reçoive le montant.

    public function test_a_trial_account_can_pay_for_the_plan_it_is_already_on(): void
    {
        Mail::fake();
        $this->sub->update(['status' => 'trial', 'expires_at' => now()->addDays(3)]);

        $data = $this->changeTo('essentiel', ['idempotency_key' => 'activate-trial-1'])
            ->assertCreated()->json('data');

        $this->assertSame('subscribe', $data['kind']);
        $this->assertEquals(59, (float) $data['amount_due']);
        $this->assertNotNull($data['invoice']);
    }

    /**
     * L'écran d'activation ne calcule rien : il rend la simulation servie par
     * l'API. Tant que le plan courant arrive sans simulation, le bouton
     * « Activer » n'a ni montant à afficher ni écran de confirmation à ouvrir.
     */
    public function test_the_plans_screen_prices_the_current_plan_for_an_account_that_must_still_pay(): void
    {
        foreach (['trial', 'trial_expired', 'expired', 'suspended'] as $status) {
            $this->sub->update(['status' => $status]);

            $plans = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription/plans')
                ->assertOk()->json('data.plans');

            $current = collect($plans)->firstWhere('slug', 'essentiel');

            $this->assertTrue($current['is_current'], "statut {$status}");
            $this->assertNotNull($current['change'], "statut {$status} : aucune simulation pour activer le plan courant");
            $this->assertSame('subscribe', $current['change']['kind'], "statut {$status}");
            $this->assertTrue($current['change']['allowed'], "statut {$status}");
            $this->assertEquals(59, (float) $current['change']['amount_due_now'], "statut {$status}");
        }
    }

    public function test_an_active_client_is_still_offered_no_simulation_towards_its_own_plan(): void
    {
        $plans = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription/plans')
            ->assertOk()->json('data.plans');

        $current = collect($plans)->firstWhere('slug', 'essentiel');
        $this->assertTrue($current['is_current']);
        $this->assertNull($current['change']);
    }

    public function test_paying_the_activation_invoice_opens_a_full_period_and_turns_the_account_active(): void
    {
        Mail::fake();
        $this->sub->update(['status' => 'trial', 'expires_at' => now()->addDays(3), 'auto_renew' => false]);

        $data = $this->changeTo('essentiel', ['idempotency_key' => 'activate-trial-2'])
            ->assertCreated()->json('data');
        $this->payInvoice(Invoice::findOrFail($data['invoice']['id']));

        $fresh = $this->sub->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($this->planId('essentiel'), $fresh->plan_id);
        $this->assertTrue($fresh->expires_at->isAfter(now()->addDays(25)));
        // Un essai converti doit être refacturé à l'échéance, sinon il
        // s'éteint en silence.
        $this->assertTrue($fresh->auto_renew);
    }

    public function test_an_upgrade_from_an_expired_account_revives_it_on_payment(): void
    {
        Mail::fake();
        $this->sub->update(['status' => 'expired', 'expires_at' => now()->subDays(5)]);

        $data = $this->changeTo('pro')->assertCreated()->json('data');
        // Rien n'a été payé d'avance sur une période échue : pas de crédit.
        $this->assertEquals(0, (float) $data['credit_applied']);
        $this->assertEquals(119, (float) $data['amount_due']);

        $this->payInvoice(Invoice::findOrFail($data['invoice']['id']));

        $fresh = $this->sub->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($this->planId('pro'), $fresh->plan_id);
        $this->assertTrue($fresh->expires_at->isFuture());
    }
}
