<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Le cycle de vie complet d'un hébergeur, de l'inscription à l'expiration.
 *
 * Les tests ci-dessus (SubscriptionSelfServiceTest) vérifient chaque
 * opération isolément. Ceux-ci vérifient qu'elles s'enchaînent : c'est là
 * que vivent les incohérences entre composants, quand chaque brique est
 * juste mais que le parcours réel se bloque.
 */
class SubscriptionLifecycleTest extends TestCase
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

        $this->org = Organization::create([
            'name' => 'DAR OMI', 'entity_type' => 'company',
            'contact_email' => 'dar-omi@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $this->hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($this->hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner',
        ]);
    }

    private function planId(string $slug): int
    {
        return (int) SubscriptionPlan::where('slug', $slug)->value('id');
    }

    /** L'abonnement d'essai tel que le crée réellement l'inscription publique. */
    private function trial(string $slug = 'essentiel'): Subscription
    {
        return Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => $this->planId($slug),
            'status'          => 'trial',
            'billing_cycle'   => 'monthly',
            'started_at'      => now(),
            'expires_at'      => now()->addDays(7),
            'auto_renew'      => false,
            'metadata'        => ['trial' => true],
        ]);
    }

    private function activeSub(string $slug = 'essentiel', array $attrs = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $this->org->id,
            'plan_id'         => $this->planId($slug),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(25),
            'expires_at'      => now()->addDays(5),
            'auto_renew'      => true,
        ], $attrs));
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'flouci']);
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);
    }

    private function changeTo(string $slug, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/change', array_merge([
            'plan_id'         => $this->planId($slug),
            'idempotency_key' => 'k-'.$slug.'-'.bin2hex(random_bytes(4)),
        ], $payload));
    }

    // ── Essai → abonnement payant ────────────────────────────────────────────

    public function test_a_trial_can_subscribe_to_its_own_plan_without_an_admin(): void
    {
        Mail::fake();
        $sub = $this->trial('essentiel');

        // Le parcours naturel : l'essai se termine, le client veut GARDER le
        // plan qu'il a choisi à l'inscription. C'est le cas le plus courant,
        // et il ne doit pas exiger de changer de formule pour payer.
        $data = $this->changeTo('essentiel')->assertCreated()->json('data');

        $this->assertSame('subscribe', $data['kind']);
        $this->assertNotNull($data['invoice'], 'une facture doit être émise');
        $this->assertEquals(59, (float) $data['amount_due']);
        $this->assertEquals(0, (float) $data['credit_applied'], 'un essai n\'a rien payé d\'avance');

        $this->pay(Invoice::findOrFail($data['invoice']['id']));

        $fresh = $sub->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->expires_at->isAfter(now()->addDays(27)));
    }

    public function test_paying_turns_the_renewal_on_so_the_account_does_not_die_silently(): void
    {
        Mail::fake();
        $sub = $this->trial();
        $this->assertFalse($sub->auto_renew, 'un essai ne se reconduit pas tout seul');

        $data = $this->changeTo('essentiel')->assertCreated()->json('data');
        $this->pay(Invoice::findOrFail($data['invoice']['id']));

        // Sans cela, le client paie une fois puis expire un mois plus tard
        // sans avoir jamais reçu de facture.
        $this->assertTrue($sub->fresh()->auto_renew);
    }

    public function test_a_paid_account_actually_receives_its_renewal_invoice(): void
    {
        Mail::fake();
        $sub = $this->trial();
        $data = $this->changeTo('essentiel')->assertCreated()->json('data');
        $this->pay(Invoice::findOrFail($data['invoice']['id']));

        // Un mois plus tard, à l'approche de l'échéance.
        $this->travelTo($sub->fresh()->expires_at->copy()->subDays(3));
        $this->artisan('invoices:generate-due')->assertSuccessful();

        $renewal = Invoice::where('subscription_id', $sub->id)
            ->where('metadata->renewal', true)->first();
        $this->assertNotNull($renewal, 'le renouvellement doit produire une facture');
        $this->assertEquals(59, (float) $renewal->amount);
    }

    public function test_an_expired_account_can_come_back_on_its_own(): void
    {
        Mail::fake();
        $sub = $this->trial();
        $this->travelTo(now()->addDays(8));
        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();
        $this->assertSame('trial_expired', $sub->fresh()->status);

        // Le compte est bloqué pour les check-ins : il doit pouvoir se
        // reprendre en main sans écrire à personne.
        $data = $this->changeTo('essentiel')->assertCreated()->json('data');
        $this->pay(Invoice::findOrFail($data['invoice']['id']));

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertTrue($sub->fresh()->auto_renew);
    }

    // ── Interférences entre factures ─────────────────────────────────────────

    public function test_an_abandoned_upgrade_never_suspends_a_paying_customer(): void
    {
        Mail::fake();
        $sub = $this->activeSub('essentiel', ['expires_at' => now()->addMonths(6)]);

        $this->changeTo('pro')->assertCreated();

        // Le client change d'avis et ne paie jamais. Un mois passe.
        $this->travelTo(now()->addDays(35));
        $this->artisan('invoices:dunning')->assertSuccessful();

        // Il est à jour de son abonnement : rien ne justifie de le suspendre.
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame($this->planId('essentiel'), $sub->fresh()->plan_id);
    }

    public function test_an_abandoned_upgrade_is_closed_out_and_frees_the_client(): void
    {
        Mail::fake();
        $sub = $this->activeSub('essentiel', ['expires_at' => now()->addMonths(6)]);
        $data = $this->changeTo('pro')->assertCreated()->json('data');

        $this->travelTo(now()->addDays(35));
        $this->artisan('invoices:dunning')->assertSuccessful();

        // La demande abandonnée est refermée, la facture annulée, et le
        // client n'est pas coincé avec un changement fantôme en travers.
        $this->assertSame(SubscriptionPlanChange::STATUS_FAILED, SubscriptionPlanChange::first()->status);
        $this->assertSame('void', Invoice::find($data['invoice']['id'])->status);

        $this->changeTo('hotel')->assertCreated();
    }

    public function test_a_pending_upgrade_does_not_block_the_renewal_invoice(): void
    {
        Mail::fake();
        $sub = $this->activeSub('essentiel');

        // Facture d'upgrade ouverte ET échéance qui approche : le client doit
        // recevoir sa facture de renouvellement quand même, sans quoi il
        // expire pour avoir hésité sur un changement de plan.
        $this->changeTo('pro')->assertCreated();
        $this->artisan('invoices:generate-due')->assertSuccessful();

        $renewal = Invoice::where('subscription_id', $sub->id)
            ->where('metadata->renewal', true)->first();
        $this->assertNotNull($renewal, 'une facture d\'upgrade ouverte ne doit pas bloquer le renouvellement');
    }

    public function test_an_open_overage_invoice_does_not_block_the_renewal_invoice(): void
    {
        Mail::fake();
        $sub = $this->activeSub('essentiel');

        Invoice::create([
            'subscription_id' => $sub->id,
            'invoice_number'  => 'INV-2026-9001',
            'amount'          => 12, 'tax_amount' => 0, 'total_amount' => 12,
            'currency' => 'TND', 'status' => 'sent', 'due_at' => now()->addDays(7),
            'metadata' => ['overage' => true],
        ]);

        $this->artisan('invoices:generate-due')->assertSuccessful();

        $this->assertNotNull(
            Invoice::where('subscription_id', $sub->id)->where('metadata->renewal', true)->first(),
            'un dépassement à régler ne doit pas priver le client de sa facture de renouvellement',
        );
    }

    public function test_the_renewal_invoice_is_never_issued_twice(): void
    {
        Mail::fake();
        $this->activeSub('essentiel');

        $this->artisan('invoices:generate-due')->assertSuccessful();
        $this->artisan('invoices:generate-due')->assertSuccessful();

        $this->assertSame(1, Invoice::where('metadata->renewal', true)->count());
    }

    // ── Reconduction : le client la pilote ───────────────────────────────────

    public function test_the_client_sees_whether_the_renewal_is_on(): void
    {
        $this->activeSub();

        $data = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data');

        $this->assertTrue($data['auto_renew']);
        $this->assertArrayHasKey('next_renewal', $data);
        $this->assertEquals(59, (float) $data['next_renewal']['amount']);
        $this->assertNotNull($data['next_renewal']['due_at']);
        // Ce que Qayed fait réellement : émettre la facture, pas prélever.
        $this->assertTrue($data['next_renewal']['auto_invoiced']);
        $this->assertFalse($data['awaiting_payment']);
    }

    public function test_a_scheduled_downgrade_shows_the_new_amount_as_the_next_due(): void
    {
        Mail::fake();
        $this->activeSub('pro', ['expires_at' => now()->addDays(20)]);
        $this->changeTo('essentiel')->assertCreated();

        $data = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data');

        // Le client paiera le plan vers lequel il descend, pas celui qu'il quitte.
        $this->assertEquals(59, (float) $data['next_renewal']['amount']);
        $this->assertSame('Essentiel', $data['next_renewal']['plan_name']);
    }

    public function test_an_account_awaiting_payment_says_so(): void
    {
        $this->trial();

        $data = $this->actingAs($this->owner)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data');

        $this->assertTrue($data['awaiting_payment']);
        // Rien ne partira tout seul tant que l'essai n'est pas réglé.
        $this->assertFalse($data['next_renewal']['auto_invoiced']);
    }

    public function test_stopping_the_renewal_stops_the_invoices_and_resuming_restores_them(): void
    {
        Mail::fake();
        $sub = $this->activeSub();

        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $this->assertSame(0, Invoice::count());
        // L'accès n'est pas coupé pour autant.
        $this->assertSame('active', $sub->fresh()->status);

        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/reactivate')->assertOk();
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $this->assertSame(1, Invoice::count());
    }

    public function test_paying_a_last_invoice_after_cancelling_does_not_silently_resubscribe(): void
    {
        Mail::fake();
        $sub = $this->activeSub();
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $invoice = Invoice::firstOrFail();

        // Le client résilie puis règle la facture déjà émise : il paie ce
        // qu'il doit, il ne se réabonne pas à son insu.
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();
        $this->pay($invoice);

        $this->assertFalse($sub->fresh()->auto_renew);
        $this->assertNotNull($sub->fresh()->cancellation_requested_at);
    }

    // ── Enchaînements ────────────────────────────────────────────────────────

    public function test_a_full_year_of_a_real_customer_stays_coherent(): void
    {
        Mail::fake();
        $sub = $this->trial('essentiel');

        // 1. L'essai devient payant.
        $d = $this->changeTo('essentiel')->assertCreated()->json('data');
        $this->pay(Invoice::findOrFail($d['invoice']['id']));
        $this->assertSame('active', $sub->fresh()->status);

        // 2. Deux renouvellements réglés.
        for ($i = 0; $i < 2; $i++) {
            $this->travelTo($sub->fresh()->expires_at->copy()->subDays(2));
            $this->artisan('invoices:generate-due')->assertSuccessful();
            $renewal = Invoice::where('metadata->renewal', true)->where('status', 'sent')->firstOrFail();
            $this->pay($renewal);
        }
        $this->assertSame('active', $sub->fresh()->status);

        // 3. Upgrade réglé.
        $d = $this->changeTo('pro')->assertCreated()->json('data');
        $this->pay(Invoice::findOrFail($d['invoice']['id']));
        $this->assertSame($this->planId('pro'), $sub->fresh()->plan_id);

        // 4. Downgrade programmé, appliqué à l'échéance.
        $this->changeTo('essentiel')->assertCreated();
        $this->travelTo($sub->fresh()->expires_at->copy()->addDay());
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();
        $this->assertSame($this->planId('essentiel'), $sub->fresh()->plan_id);

        // 5. Résiliation : l'accès court jusqu'au terme payé.
        $this->actingAs($this->owner)->postJson('/api/v1/hotel/subscription/cancel')->assertOk();
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertFalse($sub->fresh()->auto_renew);

        // Rien n'est resté en travers : aucun changement en vol, aucune
        // facture ouverte inexplicable.
        $this->assertSame(0, SubscriptionPlanChange::whereIn('status', SubscriptionPlanChange::IN_FLIGHT)->count());
        $this->assertSame(0, Invoice::whereIn('status', ['sent', 'overdue'])->count());
    }

    public function test_a_plan_change_during_an_open_renewal_never_double_charges(): void
    {
        Mail::fake();
        $sub = $this->activeSub('essentiel');

        // Facture de renouvellement émise, puis le client décide d'upgrader.
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $renewal = Invoice::where('metadata->renewal', true)->firstOrFail();

        $d = $this->changeTo('pro')->assertCreated()->json('data');
        $upgrade = Invoice::findOrFail($d['invoice']['id']);
        $this->pay($upgrade);

        // L'upgrade a ouvert une période complète payée : la facture de
        // renouvellement de l'ancienne période n'a plus d'objet.
        $this->assertSame($this->planId('pro'), $sub->fresh()->plan_id);
        $this->assertSame('void', $renewal->fresh()->status, 'le renouvellement devenu sans objet doit être annulé');
        $this->assertSame(1, Invoice::where('status', 'paid')->count());
    }
}
