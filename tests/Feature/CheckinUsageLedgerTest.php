<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\Subscription\CheckinQuota;
use App\Services\Subscription\CheckinUsageRecorder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Registre de consommation des check-ins : ce qui consomme du quota, ce qui
 * n'en consomme pas, et l'impossibilité de consommer deux fois.
 *
 * Le fil conducteur : une fiche = au plus une consommation, définitive,
 * garantie par la base (unicité sur check_in_id) et non par la discipline
 * du code appelant.
 */
class CheckinUsageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Hotel $hotel;
    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        [$this->org, $this->hotel] = $this->makeTenant('DAR OMI TEST', 'dar-omi@test.tn');
        $this->receptionist = User::factory()->receptionist($this->hotel)->create([
            'organization_id' => $this->org->id,
        ]);
    }

    /** @return array{0: Organization, 1: Hotel} */
    private function makeTenant(string $name, string $email, string $slug = 'essentiel'): array
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => $email, 'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);

        Subscription::create([
            'organization_id' => $org->id,
            'plan_id'         => SubscriptionPlan::where('slug', $slug)->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonth(), 'expires_at' => now()->addMonth(),
        ]);

        return [$org, $hotel];
    }

    /** Fiche prête à être finalisée (un voyageur au moins est exigé). */
    private function draftWithGuest(?Hotel $hotel = null, ?User $creator = null): CheckIn
    {
        $hotel   ??= $this->hotel;
        $creator ??= $this->receptionist;

        $checkIn = CheckIn::factory()->for($hotel)->draft()->create(['created_by' => $creator->id]);
        app(CheckInService::class)->addGuest($checkIn, $creator, [
            'first_name' => 'Ahmed', 'last_name' => 'Ben Ali',
            'nationality_code' => 'TUN', 'date_of_birth' => '1990-01-01',
        ]);

        return $checkIn->fresh();
    }

    // ── Ce qui consomme, et ce qui ne consomme pas ───────────────────────────

    public function test_a_draft_consumes_nothing_until_it_is_finalised(): void
    {
        $checkIn = $this->draftWithGuest();

        // Le défaut corrigé : l'ancien comptage prenait les brouillons.
        $this->assertSame(0, CheckinUsageEvent::count());
        $this->assertSame(0, CheckinQuota::usedInMonth($this->org));

        app(CheckInService::class)->complete($checkIn, $this->receptionist);

        $this->assertSame(1, CheckinUsageEvent::count());
        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    public function test_an_abandoned_draft_never_becomes_billable(): void
    {
        $this->draftWithGuest();
        $this->draftWithGuest();
        $this->draftWithGuest();

        $this->assertSame(0, CheckinQuota::usedInMonth($this->org));
    }

    public function test_a_failed_finalisation_leaves_no_consumption(): void
    {
        // Sans voyageur, la finalisation est refusée : rien ne doit rester.
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->expectException(\DomainException::class);

        try {
            app(CheckInService::class)->complete($checkIn, $this->receptionist);
        } finally {
            $this->assertSame(0, CheckinUsageEvent::count());
            $this->assertSame('draft', $checkIn->fresh()->status);
        }
    }

    public function test_later_edits_and_checkout_do_not_consume_again(): void
    {
        $checkIn = $this->draftWithGuest();
        $service = app(CheckInService::class);
        $service->complete($checkIn, $this->receptionist);

        $service->checkout($checkIn->fresh(), now()->addDay()->toDateString(), $this->receptionist);
        $service->revertCheckout($checkIn->fresh(), $this->receptionist);
        $checkIn->fresh()->update(['notes' => 'chambre changée']);

        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    // ── Annulation : règle explicite ─────────────────────────────────────────

    public function test_cancelling_a_declared_stay_keeps_the_consumption_and_dates_it(): void
    {
        $checkIn = $this->draftWithGuest();
        $service = app(CheckInService::class);
        $service->complete($checkIn, $this->receptionist);

        $service->cancel($checkIn->fresh(), 'client ne s\'est pas présenté', $this->receptionist);

        // La fiche a été déclarée : la consommation reste due (historique
        // immuable, quota non contournable en fin de mois)…
        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
        // …mais l'annulation est lisible.
        $this->assertSame(1, CheckinQuota::cancelledInMonth($this->org));
        $this->assertNotNull(CheckinUsageEvent::first()->cancelled_at);
    }

    public function test_cancelling_a_draft_consumes_nothing(): void
    {
        $checkIn = $this->draftWithGuest();

        app(CheckInService::class)->cancel($checkIn, 'erreur de saisie', $this->receptionist);

        $this->assertSame(0, CheckinUsageEvent::count());
        $this->assertSame(0, CheckinQuota::usedInMonth($this->org));
    }

    // ── Idempotence ──────────────────────────────────────────────────────────

    public function test_double_click_on_finalise_consumes_once(): void
    {
        $checkIn = $this->draftWithGuest();

        $first = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();
        // Deuxième requête : la fiche n'est plus un brouillon, elle est refusée.
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertStatus(422);

        $this->assertSame('active', $first->json('data.status'));
        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    public function test_replaying_the_recorder_never_creates_a_second_consumption(): void
    {
        // Rejeu HTTP, job relancé, webhook répété, reprise de migration : tous
        // repassent par le même enregistrement. L'unicité SQL absorbe.
        $checkIn = $this->draftWithGuest();
        app(CheckInService::class)->complete($checkIn, $this->receptionist);

        $this->assertFalse(CheckinUsageRecorder::record($checkIn->fresh()));
        $this->assertFalse(CheckinUsageRecorder::record($checkIn->fresh()));
        CheckinUsageRecorder::recordSafely($checkIn->fresh());

        $this->assertSame(1, CheckinUsageEvent::where('check_in_id', $checkIn->id)->count());
        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    public function test_the_database_refuses_a_duplicate_consumption(): void
    {
        // Filet de sécurité de dernier recours : même un INSERT direct qui
        // contournerait le service ne peut pas facturer deux fois.
        $checkIn = $this->draftWithGuest();
        app(CheckInService::class)->complete($checkIn, $this->receptionist);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('checkin_usage_events')->insert([
            'check_in_id'     => $checkIn->id,
            'organization_id' => $this->org->id,
            'hotel_id'        => $this->hotel->id,
            'period'          => now()->startOfMonth()->toDateString(),
            'consumed_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_concurrent_finalisations_of_the_same_fiche_consume_once(): void
    {
        // Deux réceptionnistes (ou un timeout suivi d'un retry) tombent sur la
        // même fiche : une seule finalisation aboutit, une seule consommation.
        $checkIn = $this->draftWithGuest();
        $service = app(CheckInService::class);
        $other   = User::factory()->receptionist($this->hotel)->create(['organization_id' => $this->org->id]);

        $service->complete($checkIn, $this->receptionist);

        try {
            $service->complete($checkIn->fresh(), $other);
            $this->fail('La seconde finalisation aurait dû être refusée.');
        } catch (\DomainException) {
            // attendu
        }

        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    public function test_a_failing_ledger_write_never_costs_the_declaration(): void
    {
        // La consommation s'écrit DANS la transaction de finalisation. Sous
        // PostgreSQL, une requête en erreur avorte toute la transaction : sans
        // isolation par SAVEPOINT, un incident de facturation ferait perdre la
        // fiche de police. On simule l'incident en supprimant la table.
        $checkIn = $this->draftWithGuest();
        DB::statement('DROP TABLE checkin_usage_events CASCADE');

        $result = app(CheckInService::class)->complete($checkIn, $this->receptionist);

        // La déclaration passe malgré tout — c'est une obligation légale.
        $this->assertSame('active', $result->status);
        $this->assertNotNull($result->completed_at);
    }

    // ── Période de rattachement ──────────────────────────────────────────────

    public function test_consumption_is_dated_on_the_declaration_not_on_the_draft(): void
    {
        // Brouillon ouvert le 31 du mois, finalisé le 2 du suivant : c'est une
        // déclaration du mois suivant.
        $lastMonth = now()->subMonthNoOverflow()->endOfMonth();
        $checkIn   = CheckIn::factory()->for($this->hotel)->create([
            'status'       => 'active',
            'created_by'   => $this->receptionist->id,
            'created_at'   => $lastMonth,
            'completed_at' => now()->startOfMonth()->addDays(1),
        ]);

        CheckinUsageRecorder::record($checkIn);

        $this->assertSame(0, CheckinQuota::usedInMonth($this->org, now()->subMonthNoOverflow()));
        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
    }

    // ── Isolation tenant ─────────────────────────────────────────────────────

    public function test_one_hotel_can_never_consume_another_organisations_quota(): void
    {
        [$otherOrg, $otherHotel] = $this->makeTenant('AUTRE MAISON', 'autre@test.tn');
        $otherUser = User::factory()->receptionist($otherHotel)->create(['organization_id' => $otherOrg->id]);

        app(CheckInService::class)->complete($this->draftWithGuest(), $this->receptionist);
        app(CheckInService::class)->complete(
            $this->draftWithGuest($otherHotel, $otherUser), $otherUser,
        );
        app(CheckInService::class)->complete(
            $this->draftWithGuest($otherHotel, $otherUser), $otherUser,
        );

        $this->assertSame(1, CheckinQuota::usedInMonth($this->org));
        $this->assertSame(2, CheckinQuota::usedInMonth($otherOrg));

        // Et chaque consommation est rattachée à la bonne organisation.
        $this->assertSame(1, CheckinUsageEvent::where('organization_id', $this->org->id)->count());
        $this->assertSame(2, CheckinUsageEvent::where('organization_id', $otherOrg->id)->count());
    }

    public function test_a_tenant_cannot_read_another_tenants_quota_or_invoices(): void
    {
        [$otherOrg, $otherHotel] = $this->makeTenant('AUTRE MAISON', 'autre@test.tn');
        $otherUser = User::factory()->hotelAdmin($otherHotel)->create(['organization_id' => $otherOrg->id]);
        app(CheckInService::class)->complete($this->draftWithGuest(), $this->receptionist);

        $quota = $this->actingAs($otherUser)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data.quota');

        // Le voisin voit SON compteur (0), jamais celui de l'organisation d'à côté.
        $this->assertSame(0, $quota['used']);
        $this->assertSame(0, count($this->actingAs($otherUser)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data')));
    }

    // ── Le client ne peut pas influencer sa facturation ──────────────────────

    public function test_a_client_cannot_change_its_own_quota_or_overage_price(): void
    {
        $admin = User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);
        $plan  = SubscriptionPlan::where('slug', 'essentiel')->firstOrFail();

        // Les routes d'édition des packs et des abonnements sont réservées à
        // l'admin plateforme : un hôtelier ne les atteint tout simplement pas.
        $this->actingAs($admin)->patchJson("/api/v1/admin/plans/{$plan->id}", [
            'features' => ['checkins_per_month' => 999999],
        ])->assertForbidden();

        $this->actingAs($admin)->getJson('/api/v1/admin/quotas')->assertForbidden();

        $this->assertSame(100, CheckinQuota::quota($this->org));
        $this->assertSame('0.600', (string) $plan->fresh()->overage_price);
    }

    public function test_amounts_come_from_the_backend_only(): void
    {
        // Le client peut envoyer ce qu'il veut : le statut de quota est
        // recalculé côté serveur à partir du pack et du registre.
        $admin = User::factory()->hotelAdmin($this->hotel)->create(['organization_id' => $this->org->id]);
        app(CheckInService::class)->complete($this->draftWithGuest(), $this->receptionist);

        $quota = $this->actingAs($admin)
            ->getJson('/api/v1/hotel/subscription?used=5000&overage_amount=0&unit_price=0')
            ->assertOk()->json('data.quota');

        $this->assertSame(1, $quota['used']);
        $this->assertEquals(0.6, $quota['unit_price']);
        $this->assertEquals(59, $quota['estimated_total']);
    }
}
