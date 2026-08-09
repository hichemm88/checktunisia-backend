<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Période de grâce : ce que le recouvrement promet doit être ce que le
 * produit fait.
 *
 * Le système annonce au client une échelle de recouvrement — facture à
 * l'échéance, relances à J+3 / J+7 / J+14, suspension à J+21. Couper le
 * check-in dès le lendemain de l'échéance rendait ces 21 jours décoratifs :
 * le client était privé du service pendant qu'on lui envoyait des relances
 * lui laissant entendre qu'il avait encore le temps.
 *
 * L'enjeu n'est pas commercial. Déclarer un voyageur est une OBLIGATION
 * LÉGALE : un établissement privé de check-in parce qu'un virement n'est pas
 * encore arrivé se retrouve en infraction pour une raison comptable. La
 * suspension reste possible — mais au terme annoncé, jamais avant.
 *
 * Ce que ces tests verrouillent, dans les deux sens :
 *  - l'accès SURVIT à l'échéance impayée jusqu'à J+21 ;
 *  - il ne survit PAS au-delà, ni pour qui a résilié, ni pour un essai, ni
 *    pour un compte que le recouvrement aurait oublié.
 */
class SubscriptionGracePeriodTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Hotel $hotel;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        Mail::fake();

        $this->org = Organization::create([
            'name' => 'DAR OMI', 'entity_type' => 'company',
            'contact_email' => 'dar-omi@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $this->hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($this->hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner',
        ]);
    }

    /**
     * Un abonnement payant dont l'échéance est passée de $daysLate jours, avec
     * la facture de renouvellement émise à l'échéance et restée impayée —
     * exactement l'état d'un client qui tarde à régler.
     */
    private function overdueAccount(int $daysLate, array $subAttrs = []): Subscription
    {
        $expired = now()->subDays($daysLate);

        $sub = Subscription::create(array_merge([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => $expired->copy()->subMonth(),
            'expires_at'      => $expired,
            'auto_renew'      => true,
        ], $subAttrs));

        Invoice::create([
            'subscription_id' => $sub->id,
            'invoice_number'  => 'INV-'.now()->year.'-9001',
            'amount'          => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status'          => 'sent',
            'due_at'          => $expired,
            'metadata'        => [
                'renewal'              => true,
                'renewal_period_start' => $expired->toIso8601String(),
                'renewal_period_end'   => $expired->copy()->addMonth()->toIso8601String(),
            ],
        ]);

        return $sub;
    }

    /** Le geste métier qui compte : la réception peut-elle ouvrir une fiche ? */
    private function canCheckIn(): bool
    {
        // Le middleware met en cache l'état de l'abonnement 60 s : sans purge,
        // un test qui change le statut lirait la décision précédente.
        Cache::flush();

        return $this->actingAs($this->owner)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date'           => now()->toDateString(),
                'expected_check_out_date' => now()->addDays(2)->toDateString(),
                'adults_count'            => 1,
                'children_count'          => 0,
                'booking_source'          => 'direct',
            ])->status() === 201;
    }

    /** Fait tourner le cycle quotidien complet, dans l'ordre du planificateur. */
    private function runDailyCycle(): void
    {
        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $this->artisan('invoices:dunning')->assertSuccessful();
    }

    // ── L'accès survit pendant la grâce ──────────────────────────────────────

    public function test_the_day_after_a_missed_due_date_the_desk_still_works(): void
    {
        $sub = $this->overdueAccount(1);

        $this->runDailyCycle();

        $this->assertSame('active', $sub->fresh()->status, 'une facture impayée ne change pas le statut');
        $this->assertTrue($this->canCheckIn(), 'la déclaration légale ne dépend pas d\'un virement');
    }

    public function test_at_the_last_reminder_the_desk_still_works(): void
    {
        // J+14 : la dernière relance part, la suspension n'est pas due.
        $sub = $this->overdueAccount(14);

        $this->runDailyCycle();

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertTrue($this->canCheckIn());
    }

    public function test_the_grace_window_matches_the_announced_recovery_schedule(): void
    {
        // Dernier jour avant la suspension annoncée.
        $sub = $this->overdueAccount(BillingService::DUNNING_SUSPEND_DAYS - 1);

        $this->runDailyCycle();

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertTrue($this->canCheckIn());
    }

    // ── Et il s'arrête au terme annoncé ──────────────────────────────────────

    public function test_at_the_announced_deadline_the_account_is_suspended(): void
    {
        $sub = $this->overdueAccount(BillingService::DUNNING_SUSPEND_DAYS);

        $this->runDailyCycle();

        $this->assertSame('suspended', $sub->fresh()->status, 'la suspension arrive bien, au terme annoncé');
        $this->assertFalse($this->canCheckIn());
    }

    /**
     * Filet de sécurité : si le recouvrement n'a jamais eu de facture sur
     * laquelle mordre (émission ratée, facture annulée à la main), la grâce
     * ne doit pas se transformer en abonnement gratuit à vie.
     */
    public function test_an_account_the_recovery_forgot_still_expires_at_the_end_of_the_grace(): void
    {
        $sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(60),
            'expires_at'      => now()->subDays(BillingService::DUNNING_SUSPEND_DAYS + 1),
            'auto_renew'      => true,
        ]);

        $this->runDailyCycle();

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertFalse($this->canCheckIn());
    }

    /**
     * Qui a résilié a choisi de partir : la grâce ne le concerne pas, aucune
     * facture ne viendra. Le service s'arrête au terme payé, comme promis.
     */
    public function test_a_cancelled_account_ends_exactly_at_the_term_it_paid_for(): void
    {
        $sub = $this->overdueAccount(1, [
            'auto_renew'                => false,
            'cancellation_requested_at' => now()->subDays(10),
        ]);

        $this->runDailyCycle();

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertFalse($this->canCheckIn());
    }

    /** Un essai n'a pas de facture impayée : rien à recouvrer, donc pas de grâce. */
    public function test_a_trial_gets_no_grace_period(): void
    {
        $sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'trial',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(8),
            'expires_at'      => now()->subDay(),
            'auto_renew'      => false,
            'metadata'        => ['trial' => true],
        ]);

        $this->runDailyCycle();

        $this->assertSame('trial_expired', $sub->fresh()->status);
        $this->assertFalse($this->canCheckIn());
    }

    // ── Sortie de grâce ──────────────────────────────────────────────────────

    public function test_paying_during_the_grace_reopens_a_full_period_exactly_once(): void
    {
        $sub = $this->overdueAccount(10);
        $this->runDailyCycle();

        $invoice = Invoice::where('subscription_id', $sub->id)->firstOrFail();
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'flouci']);
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);

        $expected = $sub->expires_at->copy()->addMonth();
        $after    = $sub->fresh();

        $this->assertSame('active', $after->status);
        $this->assertTrue($after->expires_at->isFuture(), 'la période repart');
        $this->assertSame($expected->toDateString(), $after->expires_at->toDateString());
        $this->assertTrue($this->canCheckIn());

        // Rejeu du même paiement : aucune seconde prolongation.
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);
        $this->assertSame($expected->toDateString(), $sub->fresh()->expires_at->toDateString());
    }

    /**
     * La commande tourne tous les jours : elle doit pouvoir passer vingt fois
     * sur le même compte en grâce sans rien écrire ni rien émettre de plus.
     */
    public function test_running_the_daily_cycle_again_changes_nothing(): void
    {
        $sub = $this->overdueAccount(5);

        $this->runDailyCycle();
        $snapshot = $sub->fresh()->only(['status', 'expires_at', 'suspended_at']);
        $events   = \App\Models\SubscriptionEvent::where('subscription_id', $sub->id)->count();

        $this->runDailyCycle();
        $this->runDailyCycle();

        $this->assertEquals($snapshot, $sub->fresh()->only(['status', 'expires_at', 'suspended_at']));
        $this->assertSame($events, \App\Models\SubscriptionEvent::where('subscription_id', $sub->id)->count());
        $this->assertSame(1, Invoice::where('subscription_id', $sub->id)->count(), 'aucune facture en double');
    }

    /** Le client doit LIRE qu'il est en sursis, et jusqu'à quand. */
    public function test_the_client_is_told_it_is_in_a_grace_period_and_until_when(): void
    {
        $sub = $this->overdueAccount(3);
        $this->runDailyCycle();

        $grace = $this->actingAs($this->owner)
            ->getJson('/api/v1/hotel/subscription')->assertOk()->json('data.grace');

        $this->assertTrue($grace['active']);
        $this->assertSame(
            $sub->expires_at->copy()->addDays(BillingService::DUNNING_SUSPEND_DAYS)->toDateString(),
            \Illuminate\Support\Carbon::parse($grace['ends_at'])->toDateString(),
        );
        $this->assertSame(BillingService::DUNNING_SUSPEND_DAYS - 3, $grace['days_left']);
    }

    /**
     * Un compte en sursis doit RESTER sous les yeux de l'admin. Le maintenir
     * en service sans le montrer reviendrait à le laisser glisser jusqu'à la
     * suspension sans que personne ne l'ait relancé.
     */
    public function test_an_account_in_grace_stays_visible_in_the_admin_alerts(): void
    {
        $this->overdueAccount(6);
        $this->runDailyCycle();

        $alerts = $this->actingAs(User::factory()->platformAdmin()->create())
            ->getJson('/api/v1/admin/dashboard')->assertOk()
            ->json('data.alerts.expiring_subscriptions');

        $this->assertCount(1, $alerts, 'le compte en retard reste à surveiller');
        $this->assertTrue($alerts[0]['grace']['active'], 'et il est signalé comme étant en sursis');
    }

    public function test_an_account_up_to_date_is_never_shown_as_in_grace(): void
    {
        Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(5),
            'expires_at'      => now()->addDays(25),
            'auto_renew'      => true,
        ]);

        $grace = $this->actingAs($this->owner)
            ->getJson('/api/v1/hotel/subscription')->assertOk()->json('data.grace');

        $this->assertFalse($grace['active']);
        $this->assertNull($grace['ends_at']);
    }
}
