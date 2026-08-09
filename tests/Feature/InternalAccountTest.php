<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OverageCharge;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Subscription\CheckinUsageRecorder;
use App\Services\Subscription\QuotaAlertService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Comptes internes — exemption commerciale.
 *
 * Certaines organisations nous appartiennent : elles exploitent Qayed sans
 * l'acheter. Les compter comme des clients fausse le chiffre d'affaires, et
 * leur envoyer des factures n'a aucun sens.
 *
 * Ces tests protègent les deux moitiés de la règle, qui se trahissent
 * facilement l'une l'autre : un compte interne ne produit JAMAIS de revenu,
 * et il garde POURTANT l'accès complet au produit. L'exemption est
 * commerciale, elle n'est ni un privilège ni une restriction fonctionnelle.
 *
 * Aucun nom d'organisation n'apparaît ici : la règle est portée par
 * `billing_mode`, jamais par l'identité d'un compte particulier.
 */
class InternalAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
    }

    /** @return array{0: Organization, 1: Hotel, 2: User, 3: Subscription} */
    private function account(string $name, string $mode, string $slug = 'essentiel', array $subAttrs = []): array
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => strtolower(str_replace(' ', '-', $name)).'@test.tn',
            'status' => 'active', 'locale' => 'fr', 'billing_mode' => $mode,
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $org->id, 'role_org' => 'owner',
        ]);
        $sub = Subscription::create(array_merge([
            'organization_id' => $org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', $slug)->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subDays(25),
            'expires_at'      => now()->addDays(5),
            'auto_renew'      => true,
        ], $subAttrs));

        return [$org, $hotel, $owner, $sub];
    }

    private function declare(Hotel $hotel, User $user, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            CheckinUsageRecorder::record(CheckIn::factory()->create([
                'hotel_id' => $hotel->id, 'status' => 'active',
                'created_by' => $user->id, 'completed_at' => now(),
            ]));
        }
    }

    private function platformAdmin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    // ── Facturation : rien ne part ───────────────────────────────────────────

    public function test_an_internal_account_never_gets_a_renewal_invoice(): void
    {
        Mail::fake();
        $this->account('Compte Interne', Organization::BILLING_INTERNAL);
        $this->account('Vrai Client', Organization::BILLING_COMMERCIAL);

        $this->artisan('invoices:generate-due')->assertSuccessful();

        // Une seule facture : celle du client. Le compte interne n'en a aucune.
        $this->assertSame(1, Invoice::count());
        $this->assertSame(
            'Vrai Client',
            Invoice::first()->subscription->organization->name,
        );
    }

    /**
     * Le back-office peut émettre une facture à la main (rattrapage, prestation
     * ponctuelle). C'est le dernier chemin capable de créer de l'argent, et le
     * seul actionné par un humain : la règle doit y tenir aussi, sinon un clic
     * suffit à faire réapparaître un compte à nous dans le chiffre d'affaires.
     */
    public function test_an_admin_cannot_hand_write_an_invoice_for_an_internal_account(): void
    {
        [$org, , , $sub] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        $this->actingAs($this->platformAdmin())
            ->postJson("/api/v1/admin/hosts/{$org->id}/invoices", [
                'subscription_id' => $sub->id,
                'amount'          => 199,
                'tax_amount'      => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'INTERNAL_ACCOUNT_NOT_BILLABLE');

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_admin_can_still_hand_write_an_invoice_for_a_real_customer(): void
    {
        Mail::fake();
        [$org, , , $sub] = $this->account('Vrai Client', Organization::BILLING_COMMERCIAL);

        $this->actingAs($this->platformAdmin())
            ->postJson("/api/v1/admin/hosts/{$org->id}/invoices", [
                'subscription_id' => $sub->id,
                'amount'          => 199,
                'tax_amount'      => 0,
            ])
            ->assertCreated();

        $this->assertSame(1, Invoice::count());
    }

    /**
     * La garde de dernier recours, au niveau du MODÈLE.
     *
     * Les gardes de service couvrent les chemins connus. Celle-ci couvre ceux
     * qui n'existent pas encore : un futur script, une commande de rattrapage,
     * un développeur pressé. Aucun code passant par Eloquent ne peut créer une
     * facture sur un compte interne — la règle cesse d'être une convention à
     * respecter pour devenir une impossibilité.
     */
    public function test_no_code_path_whatsoever_can_create_an_invoice_for_an_internal_account(): void
    {
        [, , , $sub] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        $this->expectException(\DomainException::class);

        // Écriture directe, sans passer par le moindre service : c'est
        // exactement ce que ferait un chemin de facturation ajouté plus tard.
        Invoice::create([
            'subscription_id' => $sub->id,
            'invoice_number'  => 'INV-2026-9999',
            'amount'          => 199, 'tax_amount' => 0, 'total_amount' => 199,
            'currency'        => 'TND', 'status' => 'sent',
        ]);
    }

    public function test_the_model_guard_leaves_real_customers_alone(): void
    {
        [, , , $sub] = $this->account('Vrai Client', Organization::BILLING_COMMERCIAL);

        Invoice::create([
            'subscription_id' => $sub->id,
            'invoice_number'  => 'INV-2026-9998',
            'amount'          => 199, 'tax_amount' => 0, 'total_amount' => 199,
            'currency'        => 'TND', 'status' => 'sent',
        ]);

        $this->assertSame(1, Invoice::count());
    }

    public function test_the_guard_holds_even_when_the_invoice_is_asked_for_directly(): void
    {
        // Le filtre de la commande ne suffit pas : n'importe quel appelant
        // (admin, code futur) doit se heurter à la même règle.
        [, , , $sub] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        $this->assertNull(app(BillingService::class)->generateRenewalInvoice($sub));
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_internal_overage_is_measured_but_never_billed(): void
    {
        Mail::fake();
        [$org, $hotel, $owner] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);
        $this->declare($hotel, $owner, 112); // quota Essentiel = 100

        $this->artisan('quota:close-month', ['--month' => now()->format('Y-m')])->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $org->id)->first();
        $this->assertNotNull($charge, 'la consommation reste mesurée — c\'est une donnée d\'exploitation');
        $this->assertSame(12, $charge->overage_count);
        // Mais elle ne vaut rien et ne devient jamais une créance.
        $this->assertEquals(0, (float) $charge->amount);
        $this->assertSame(OverageCharge::STATUS_EXCLUDED_INTERNAL, $charge->status);
        $this->assertNull($charge->invoice_id);
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_commercial_overage_is_still_billed_normally(): void
    {
        Mail::fake();
        [$org, $hotel, $owner] = $this->account('Vrai Client', Organization::BILLING_COMMERCIAL);
        $this->declare($hotel, $owner, 112);

        $this->artisan('quota:close-month', ['--month' => now()->format('Y-m')])->assertSuccessful();

        $charge = OverageCharge::where('organization_id', $org->id)->firstOrFail();
        $this->assertEquals(7.2, (float) $charge->amount); // 12 × 0,600
        $this->assertNotNull($charge->invoice_id, 'le dépassement d\'un client reste facturé');
    }

    public function test_an_internal_account_is_never_chased_or_suspended(): void
    {
        Mail::fake();

        // Le scénario réel : le compte était un CLIENT, il a reçu une facture,
        // puis il est passé chez nous. La facture reste impayée et échue.
        [$org, , , $sub] = $this->account('Ancien Client', Organization::BILLING_COMMERCIAL);

        $invoice = Invoice::create([
            'subscription_id' => $sub->id, 'invoice_number' => 'INV-2026-8001',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'sent', 'due_at' => now()->subDays(40), 'metadata' => ['renewal' => true],
        ]);

        $org->update(['billing_mode' => Organization::BILLING_INTERNAL]);

        $this->artisan('invoices:dunning')->assertSuccessful();

        $this->assertSame('sent', $invoice->fresh()->status, 'aucune créance ne court contre un compte interne');
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertEmpty($invoice->fresh()->metadata['dunning_sent'] ?? []);
    }

    public function test_an_internal_account_does_not_expire_commercially(): void
    {
        Mail::fake();

        // Les deux comptes sont aussi en retard l'un que l'autre, et tous deux
        // au-delà de la période de grâce accordée au recouvrement : seul le
        // périmètre commercial les distingue.
        $wellPast = now()->subDays(\App\Services\Billing\BillingService::DUNNING_SUSPEND_DAYS + 1);

        [, , , $internal] = $this->account('Compte Interne', Organization::BILLING_INTERNAL, 'essentiel', [
            'expires_at' => $wellPast,
        ]);
        [, , , $client] = $this->account('Vrai Client', Organization::BILLING_COMMERCIAL, 'essentiel', [
            'expires_at' => $wellPast,
        ]);

        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();

        $this->assertSame('active', $internal->fresh()->status, 'nous ne nous coupons pas nous-mêmes');
        $this->assertSame('expired', $client->fresh()->status);
    }

    public function test_quota_alerts_that_sell_something_are_not_sent_to_an_internal_account(): void
    {
        Mail::fake();
        [$org, $hotel, $owner] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);
        $this->declare($hotel, $owner, 95); // 95 % du quota

        QuotaAlertService::evaluate($org->fresh());

        // Ces alertes annoncent un coût de dépassement : sans objet ici.
        $this->assertSame(0, \App\Models\QuotaAlert::count());
    }

    // ── Métriques : zéro partout ─────────────────────────────────────────────

    public function test_an_internal_account_weighs_nothing_in_the_commercial_metrics(): void
    {
        $this->account('Interne A', Organization::BILLING_INTERNAL, 'hotel');   // 299 TND
        $this->account('Interne B', Organization::BILLING_INTERNAL, 'pro');     // 119 TND
        $this->account('Vrai Client', Organization::BILLING_COMMERCIAL, 'pro'); // 119 TND

        $data = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/metrics/kpis')->assertOk()->json('data');

        // Seul le client compte — plusieurs comptes internes sont exclus.
        $this->assertEquals(119, $data['mrr']['current']);
        $this->assertSame(1, $data['arpu']['paying_customers']);
        $this->assertEquals(119, $data['arpu']['value']);
    }

    public function test_the_dashboard_mrr_excludes_internal_accounts_and_says_so(): void
    {
        $this->account('Interne', Organization::BILLING_INTERNAL, 'hotel');
        $this->account('Client Un', Organization::BILLING_COMMERCIAL, 'essentiel');
        $this->account('Client Deux', Organization::BILLING_COMMERCIAL, 'pro');

        $data = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertEquals(178, $data['mrr']); // 59 + 119, sans les 299
        $this->assertSame(
            ['Client Deux', 'Client Un'],
            collect($data['mrr_breakdown'])->pluck('customer')->sort()->values()->all(),
        );

        // Le parc reste lisible : le compte interne existe, il est juste
        // hors périmètre commercial.
        $this->assertSame(3, $data['organizations']['total']);
        $this->assertSame(2, $data['organizations']['commercial']);
        $this->assertSame(1, $data['organizations']['internal']);
    }

    public function test_an_internal_account_is_not_counted_in_commercial_churn(): void
    {
        $this->account('Interne', Organization::BILLING_INTERNAL, 'pro', [
            'status' => 'cancelled', 'cancelled_at' => now()->subDay(),
        ]);

        $data = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/metrics/kpis')->assertOk()->json('data');

        $this->assertSame(0, $data['churn']['churned_customers']);
        $this->assertEquals(0, $data['mrr']['churned_this_month']);
    }

    public function test_the_admin_sheet_shows_zero_revenue_for_an_internal_account(): void
    {
        [$org] = $this->account('Compte Interne', Organization::BILLING_INTERNAL, 'hotel');

        $data = $this->actingAs($this->platformAdmin())
            ->getJson("/api/v1/admin/hosts/{$org->id}")->assertOk()->json('data');

        // Zéro explicite, pas une absence qu'on prendrait pour une donnée manquante.
        $this->assertSame(0.0, (float) $data['metrics']['mrr']);
        $this->assertSame(Organization::BILLING_INTERNAL, $data['billing_mode']);
    }

    // ── Le produit reste entier ──────────────────────────────────────────────

    public function test_an_internal_account_keeps_full_access_to_the_product(): void
    {
        [$org, $hotel, $owner] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        // Consommation bien au-delà du quota : jamais bloquée.
        $this->declare($hotel, $owner, 250);
        $this->assertSame(250, \App\Services\Subscription\CheckinQuota::usedInMonth($org->fresh()));

        $this->actingAs($owner)
            ->withHeader('X-Hotel-Id', $hotel->id)
            ->getJson('/api/v1/hotel/check-ins')
            ->assertOk();
    }

    public function test_the_subscription_screen_says_internal_and_offers_nothing_to_pay(): void
    {
        [, , $owner] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        $data = $this->actingAs($owner)->getJson('/api/v1/hotel/subscription')
            ->assertOk()->json('data');

        $this->assertTrue($data['is_internal']);
        $this->assertSame(Organization::BILLING_INTERNAL, $data['billing_mode']);
        $this->assertNull($data['next_renewal'], 'pas d\'échéance commerciale');
        $this->assertFalse($data['awaiting_payment']);
    }

    public function test_an_internal_account_cannot_start_a_commercial_plan_change(): void
    {
        [, , $owner] = $this->account('Compte Interne', Organization::BILLING_INTERNAL);

        $this->actingAs($owner)->postJson('/api/v1/hotel/subscription/change', [
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'pro')->value('id'),
            'idempotency_key' => 'internal-attempt-1',
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'PLAN_CHANGE_NOT_ALLOWED');

        $this->assertSame(0, Invoice::count());
    }

    /**
     * L'entonnoir commercial du tableau de bord admin (essais en cours,
     * conversion, échéances à surveiller) mesure une activité de VENTE. Un
     * compte à nous n'a jamais été vendu : l'y compter fait lire une
     * conversion et des relances qui n'existent pas, et contredit les KPI de
     * l'écran voisin, qui l'excluent déjà.
     */
    public function test_the_admin_funnel_never_counts_an_internal_account(): void
    {
        $this->account('Interne En Essai', Organization::BILLING_INTERNAL, 'essentiel', [
            'status'     => 'trial',
            'expires_at' => now()->addDays(3),
            'metadata'   => ['trial' => true],
        ]);
        $this->account('Interne Actif', Organization::BILLING_INTERNAL, 'essentiel', [
            'status'     => 'active',
            'expires_at' => now()->addDays(10),
        ]);

        $data = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(0, $data['trials']['in_progress'], 'aucun essai commercial');
        $this->assertSame([], $data['trials']['expiring_soon'], 'aucune fin d\'essai à relancer');
        $this->assertNull($data['trials']['conversion_rate'], 'aucune cohorte commerciale : pas de taux');
        $this->assertSame([], $data['alerts']['expiring_subscriptions'], 'aucune échéance commerciale à surveiller');
    }

    public function test_the_admin_funnel_still_counts_a_real_customer(): void
    {
        $this->account('Vrai Client', Organization::BILLING_COMMERCIAL, 'essentiel', [
            'status'     => 'trial',
            'expires_at' => now()->addDays(3),
            'metadata'   => ['trial' => true],
        ]);

        $data = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $data['trials']['in_progress']);
        $this->assertCount(1, $data['trials']['expiring_soon']);
    }

    // ── Historique et isolation ──────────────────────────────────────────────

    public function test_historic_invoices_of_an_internal_account_are_preserved_and_readable(): void
    {
        [$org, , $owner, $sub] = $this->account('Ancien Client', Organization::BILLING_COMMERCIAL);

        // Facture émise DU TEMPS où le compte était commercial — puis le
        // compte passe chez nous. L'exemption n'efface pas l'histoire.
        Invoice::create([
            'subscription_id' => $sub->id, 'invoice_number' => 'INV-2025-0042',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'paid', 'paid_at' => now()->subMonths(3), 'due_at' => now()->subMonths(3),
        ]);

        $org->update(['billing_mode' => Organization::BILLING_INTERNAL]);

        $invoices = $this->actingAs($owner)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data');

        $this->assertCount(1, $invoices, 'le passage en interne n\'efface aucun historique');
        $this->assertSame('INV-2025-0042', $invoices[0]['invoice_number']);
    }

    public function test_marking_one_account_internal_leaves_every_other_tenant_untouched(): void
    {
        Mail::fake();
        [$orgA] = $this->account('Client A', Organization::BILLING_COMMERCIAL);
        [$orgB, , , $subB] = $this->account('Client B', Organization::BILLING_COMMERCIAL);

        $orgA->update(['billing_mode' => Organization::BILLING_INTERNAL]);

        $this->artisan('invoices:generate-due')->assertSuccessful();

        // B continue d'être facturé exactement comme avant.
        $this->assertSame(1, Invoice::count());
        $this->assertSame($subB->id, Invoice::first()->subscription_id);
        $this->assertTrue($orgB->fresh()->isCommercial());
    }

    public function test_an_account_is_commercial_unless_stated_otherwise(): void
    {
        // Le défaut protège le revenu : un compte créé sans rien préciser est
        // un client. C'est l'exemption qui doit être explicite, jamais l'inverse.
        $org = Organization::create([
            'name' => 'Sans Mention', 'entity_type' => 'company',
            'contact_email' => 'sans@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);

        $this->assertSame(Organization::BILLING_COMMERCIAL, $org->fresh()->billing_mode);
        $this->assertTrue($org->isCommercial());
    }

    /**
     * Non-régression : toute future fonctionnalité de facturation doit passer
     * par le scope commercial. Si ce test tombe, c'est qu'un chemin de revenu
     * a été ajouté sans cette garde — et qu'un compte interne va être facturé.
     */
    public function test_no_billing_path_ever_produces_money_for_an_internal_account(): void
    {
        Mail::fake();
        [$org, $hotel, $owner, $sub] = $this->account('Compte Interne', Organization::BILLING_INTERNAL, 'essentiel', [
            'expires_at' => now()->subDays(30),
        ]);
        $this->declare($hotel, $owner, 400);

        // On déclenche TOUT ce qui, dans le système, peut créer de l'argent.
        $this->artisan('invoices:generate-due')->assertSuccessful();
        $this->artisan('quota:close-month', ['--month' => now()->format('Y-m')])->assertSuccessful();
        $this->artisan('invoices:dunning')->assertSuccessful();
        $this->artisan('subscriptions:expire-overdue')->assertSuccessful();
        $this->artisan('subscriptions:apply-plan-changes')->assertSuccessful();
        $this->artisan('subscriptions:notify-expiring')->assertSuccessful();

        $this->assertSame(0, Invoice::count(), 'aucune facture');
        $this->assertSame(0, \App\Models\Payment::count(), 'aucun paiement attendu');
        $this->assertEquals(0, (float) OverageCharge::where('organization_id', $org->id)->sum('amount'), 'aucun dépassement valorisé');
        $this->assertSame('active', $sub->fresh()->status, 'jamais suspendu ni expiré');

        // Et zéro dans toutes les métriques commerciales.
        $kpis = $this->actingAs($this->platformAdmin())
            ->getJson('/api/v1/admin/metrics/kpis')->assertOk()->json('data');
        $this->assertEquals(0, $kpis['mrr']['current']);
        $this->assertSame(0, $kpis['arpu']['paying_customers']);
    }
}
