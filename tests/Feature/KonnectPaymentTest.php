<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bascule de Flouci vers Konnect.
 *
 * Ce qui change réellement pour le client tient en peu de choses : la page de
 * paiement, et le fait qu'un règlement soit désormais constaté même s'il ne
 * revient pas (voir KonnectWebhookTest). Tout le reste — facture, encaissement,
 * prolongation d'abonnement — ne connaît aucun prestataire et ne doit pas
 * bouger.
 *
 * Les tests ci-dessous gardent les points où une erreur ne se verrait pas
 * tout de suite : l'environnement employé, le montant transmis, et ce qui est
 * compté comme un paiement abouti.
 */
class KonnectPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $owner;
    private User $admin;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);

        $this->org = Organization::create([
            'name' => 'DAR OMI', 'entity_type' => 'company',
            'contact_email' => 'dar-omi@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner',
            'first_name' => 'Hichem', 'last_name' => 'Mathlouthi',
        ]);
        $this->admin = User::factory()->platformAdmin()->create();

        $sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonth(), 'expires_at' => now()->addDays(20),
            'auto_renew'      => true,
        ]);

        $this->invoice = Invoice::create([
            'subscription_id' => $sub->id, 'invoice_number' => 'INV-'.now()->year.'-9001',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59.500, 'currency' => 'TND',
            'status' => 'sent', 'due_at' => now()->addDays(7),
        ]);
    }

    private function konnectConfigured(array $overrides = []): void
    {
        PlatformSetting::get()->update(array_merge([
            'konnect_enabled'     => true,
            'konnect_environment' => 'sandbox',
            'konnect_api_key'     => '5f7a209aeb3f76490ac4a3d1:secret-de-simulation',
            'konnect_wallet_id'   => '5f7a209aeb3f76490ac4a3d1',
            'flouci_enabled'      => false,
        ], $overrides));
    }

    private function fakeInit(string $ref = 'KONNECT-REF-1'): void
    {
        Http::fake(['*init-payment*' => Http::response([
            'payUrl'     => "https://gateway.sandbox.konnect.network/pay?payment_ref={$ref}",
            'paymentRef' => $ref,
        ])]);
    }

    private function initiate(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)
            ->postJson('/api/v1/hotel/payments/initiate', ['invoice_id' => $this->invoice->id]);
    }

    // ── Ce qui part chez Konnect ─────────────────────────────────────────────

    public function test_the_initiation_carries_the_api_key_the_wallet_and_the_amount_in_millimes(): void
    {
        $this->konnectConfigured();
        $this->fakeInit();

        $this->initiate()
            ->assertCreated()
            ->assertJsonPath('data.payment_url', 'https://gateway.sandbox.konnect.network/pay?payment_ref=KONNECT-REF-1');

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', '5f7a209aeb3f76490ac4a3d1:secret-de-simulation')
                && $request['receiverWalletId'] === '5f7a209aeb3f76490ac4a3d1'
                && $request['token'] === 'TND'
                // 59,500 TND = 59500 millimes. Un facteur 1000 oublié se
                // facture au millième du prix, et personne ne s'en plaint.
                && $request['amount'] === 59500
                && $request['type'] === 'immediate';
        });

        $payment = Payment::where('invoice_id', $this->invoice->id)->firstOrFail();
        $this->assertSame('konnect', $payment->provider);
        $this->assertSame('KONNECT-REF-1', $payment->provider_payment_id);
    }

    /** Les trois moyens de règlement demandés doivent être proposés au client. */
    public function test_the_three_payment_methods_are_offered(): void
    {
        $this->konnectConfigured();
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request['acceptedPaymentMethods'] === ['wallet', 'bank_card', 'e-DINAR']);
    }

    /**
     * `orderId` porte le NUMÉRO DE FACTURE, pas notre UUID de suivi : c'est par
     * lui qu'on rapproche un encaissement dans le tableau de bord Konnect le
     * jour d'un litige. Un UUID n'y dirait rien à personne.
     */
    public function test_the_invoice_number_travels_as_the_order_id_and_the_customer_is_prefilled(): void
    {
        $this->konnectConfigured();
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request['orderId'] === $this->invoice->invoice_number
            && $request['firstName'] === 'Hichem'
            && $request['email'] === $this->owner->email);
    }

    /**
     * L'erreur qui ne se voit pas : encaisser en SIMULATION en croyant être en
     * production. L'argent n'arrive jamais, et tout se passe comme si.
     */
    public function test_the_environment_chooses_the_api_and_nothing_else_does(): void
    {
        $this->konnectConfigured(['konnect_environment' => 'production']);
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.konnect.network/api/v2/payments/init-payment'));
    }

    public function test_the_simulation_environment_never_calls_the_production_api(): void
    {
        $this->konnectConfigured(['konnect_environment' => 'sandbox']);
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.preprod.konnect.network/api/v2/payments/init-payment'));
    }

    /** La saisie du back-office prime sur l'environnement, comme pour Flouci. */
    public function test_the_credentials_entered_in_the_back_office_win_over_the_environment(): void
    {
        $this->konnectConfigured();
        config(['konnect.api_key' => 'cle-environnement', 'konnect.wallet_id' => 'portefeuille-environnement']);
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', '5f7a209aeb3f76490ac4a3d1:secret-de-simulation')
            && $request['receiverWalletId'] === '5f7a209aeb3f76490ac4a3d1');
    }

    public function test_the_environment_still_provides_the_credentials_when_the_back_office_has_none(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled'   => true,
            'konnect_api_key'   => null,
            'konnect_wallet_id' => null,
        ]);
        config(['konnect.api_key' => 'cle-environnement', 'konnect.wallet_id' => 'portefeuille-environnement']);
        $this->fakeInit();

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'cle-environnement'));
    }

    // ── Un canal fermé se dit fermé ──────────────────────────────────────────

    public function test_a_channel_switched_on_without_credentials_is_treated_as_closed(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled'   => true,
            'konnect_api_key'   => null,
            'konnect_wallet_id' => null,
            'flouci_enabled'    => false,
        ]);
        config(['konnect.api_key' => '', 'konnect.wallet_id' => '']);
        Http::fake();

        $this->initiate()
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'PAYMENT_METHOD_UNAVAILABLE');

        Http::assertNothingSent();
    }

    /**
     * LE geste réel du back-office : on lève l'interrupteur ET on saisit les
     * identifiants dans le MÊME enregistrement.
     *
     * Le garde de complétude raisonne sur l'état RÉSULTANT, donc sur un clone
     * rempli avec la requête. La clé d'API étant chiffrée par un cast, ce
     * chemin — écrire puis relire une valeur chiffrée sur un modèle jamais
     * enregistré — n'était couvert par aucun test. S'il échoue, l'exploitant
     * voit son canal refuser de s'ouvrir sans jamais comprendre pourquoi :
     * il a pourtant rempli les deux champs.
     */
    public function test_konnect_opens_when_the_switch_and_the_credentials_are_saved_together(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled' => false, 'konnect_api_key' => null, 'konnect_wallet_id' => null,
        ]);
        config(['konnect.api_key' => '', 'konnect.wallet_id' => '']);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'konnect_enabled'     => true,
                'konnect_environment' => 'sandbox',
                'konnect_api_key'     => '5f7a209aeb3f76490ac4a3d1:secret',
                'konnect_wallet_id'   => '5f7a209aeb3f76490ac4a3d1',
            ])
            ->assertOk()
            ->assertJsonPath('data.konnect_enabled', true)
            ->assertJsonPath('data.online_payment_enabled', true);

        $fresh = PlatformSetting::get()->fresh();
        $this->assertTrue((bool) $fresh->konnect_enabled, "l'interrupteur reste levé après enregistrement");
        $this->assertSame('5f7a209aeb3f76490ac4a3d1:secret', $fresh->konnect_api_key);
        $this->assertTrue($fresh->konnectReady());
    }

    /**
     * Deuxième enregistrement, champs d'identifiants laissés VIDES (« laisser
     * vide pour ne pas changer »). Le front ne les transmet alors pas du tout :
     * le garde doit s'appuyer sur ce qui est déjà en base, sinon toute
     * modification ultérieure refermerait le canal.
     */
    public function test_a_later_save_without_retyping_the_credentials_keeps_the_channel_open(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled'   => true,
            'konnect_api_key'   => '5f7a209aeb3f76490ac4a3d1:secret',
            'konnect_wallet_id' => '5f7a209aeb3f76490ac4a3d1',
        ]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'konnect_enabled'     => true,
                'konnect_environment' => 'production',
            ])
            ->assertOk()
            ->assertJsonPath('data.konnect_enabled', true);

        $fresh = PlatformSetting::get()->fresh();
        $this->assertSame('production', $fresh->konnect_environment);
        $this->assertSame('5f7a209aeb3f76490ac4a3d1:secret', $fresh->konnect_api_key, "les identifiants ne sont pas effacés");
    }

    /**
     * L'écran de configuration n'est pas une prison.
     *
     * La ligne de réglages livrée par défaut ouvre le virement avec un
     * bénéficiaire mais NI IBAN NI RIB. Comme l'écran renvoie les trois canaux
     * à chaque enregistrement, ce seul défaut refusait TOUTE écriture : on
     * venait configurer Konnect, on se voyait opposer un champ de virement
     * jamais touché, et rien ne s'enregistrait — pas même la correction du
     * virement, puisque le refus est global.
     */
    public function test_an_unrelated_channel_already_incomplete_does_not_block_the_save(): void
    {
        PlatformSetting::get()->update([
            'virement_enabled'     => true,
            'virement_beneficiary' => 'Kasbahost Sarl',
            'virement_iban'        => null,
            'virement_rib'         => null,
            'konnect_enabled'      => false,
            'konnect_api_key'      => null,
            'konnect_wallet_id'    => null,
        ]);
        config(['konnect.api_key' => '', 'konnect.wallet_id' => '']);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'konnect_enabled'     => true,
                'konnect_environment' => 'production',
                'konnect_api_key'     => '5f7a209aeb3f76490ac4a3d1:secret',
                'konnect_wallet_id'   => '5f7a209aeb3f76490ac4a3d1',
                // L'écran renvoie aussi le virement, tel qu'il est.
                'virement_enabled'     => true,
                'virement_beneficiary' => 'Kasbahost Sarl',
                'virement_iban'        => '',
                'virement_rib'         => '',
            ])
            ->assertOk();

        $this->assertTrue((bool) PlatformSetting::get()->fresh()->konnect_enabled);
    }

    /** Mais on n'ouvre toujours pas un canal muet : la protection tient. */
    public function test_opening_a_healthy_channel_into_an_unusable_state_is_still_refused(): void
    {
        PlatformSetting::get()->update([
            'virement_enabled'     => true,
            'virement_beneficiary' => 'Kasbahost Sarl',
            'virement_iban'        => 'TN5910006035010054930010',
        ]);

        // Le virement était praticable : le vider est bien introduit ICI.
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'virement_enabled' => true,
                'virement_iban'    => '',
                'virement_rib'     => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_CHANNEL_INCOMPLETE');
    }

    public function test_switching_konnect_on_without_credentials_is_refused_at_the_back_office(): void
    {
        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', ['konnect_enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_CHANNEL_INCOMPLETE')
            ->assertJsonPath('errors.0.field', 'konnect_api_key');

        $this->assertFalse((bool) PlatformSetting::get()->fresh()->konnect_enabled);
    }

    public function test_a_gateway_outage_is_announced_as_temporary(): void
    {
        $this->konnectConfigured();
        Http::fake(['*init-payment*' => Http::response('', 500)]);

        $this->initiate()
            ->assertStatus(502)
            ->assertJsonPath('errors.0.code', 'PAYMENT_GATEWAY_ERROR');

        $this->assertSame(0, Payment::where('invoice_id', $this->invoice->id)->count());
    }

    /** Une réponse 200 sans lien de paiement n'est pas un succès. */
    public function test_a_response_without_a_payment_url_is_an_error_not_a_payment(): void
    {
        $this->konnectConfigured();
        Http::fake(['*init-payment*' => Http::response(['paymentRef' => 'REF-SANS-LIEN'])]);

        $this->initiate()->assertStatus(502);

        $this->assertSame(0, Payment::where('invoice_id', $this->invoice->id)->count());
    }

    // ── Ce qui compte comme un paiement abouti ───────────────────────────────

    private function pendingPayment(string $ref = 'KONNECT-REF-1'): Payment
    {
        return Payment::create([
            'invoice_id'          => $this->invoice->id,
            'provider'            => 'konnect',
            'provider_payment_id' => $ref,
            'status'              => 'pending',
            'amount'              => $this->invoice->total_amount,
            'currency'            => 'TND',
            'expires_at'          => now()->addMinutes(15),
        ]);
    }

    private function fakeDetails(array $payment): void
    {
        Http::fake(['*/payments/*' => Http::response(['payment' => $payment])]);
    }

    public function test_a_completed_and_fully_paid_payment_settles_the_invoice(): void
    {
        $this->konnectConfigured();
        $payment = $this->pendingPayment();
        $this->fakeDetails([
            'status' => 'completed', 'amount' => 59500, 'reachedAmount' => 59500, 'amountDue' => 0,
            'transactions' => [['status' => 'success']],
        ]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame('konnect', $this->invoice->fresh()->payment_method);
    }

    /**
     * Konnect sait encaisser PARTIELLEMENT. Un statut favorable avec un reste
     * à devoir ne doit pas prolonger un abonnement : ce serait offrir la
     * différence, en silence et sans trace comptable.
     */
    public function test_a_partial_payment_is_not_a_settlement(): void
    {
        $this->konnectConfigured();
        $payment = $this->pendingPayment();
        $this->fakeDetails([
            'status' => 'completed', 'amount' => 59500, 'reachedAmount' => 20000, 'amountDue' => 39500,
            'transactions' => [['status' => 'success']],
        ]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame('sent', $this->invoice->fresh()->status);
    }

    /** Aucune transaction réellement aboutie ⇒ aucun encaissement. */
    public function test_a_completed_payment_without_a_successful_transaction_is_not_a_settlement(): void
    {
        $this->konnectConfigured();
        $payment = $this->pendingPayment();
        $this->fakeDetails([
            'status' => 'completed', 'amount' => 59500, 'reachedAmount' => 59500,
            'transactions' => [['status' => 'failed_payment']],
        ]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame('sent', $this->invoice->fresh()->status);
    }

    /**
     * « En attente » n'est pas « échoué ».
     *
     * Le client revient parfois sur la page de succès une fraction de seconde
     * avant que Konnect ne tranche. Sceller le paiement à cet instant le
     * rendrait clos pour le webhook qui arrive juste après : l'argent est
     * parti, le règlement n'est jamais constaté, la facture part en relance et
     * finit par suspendre un client à jour.
     */
    public function test_a_payment_the_gateway_has_not_settled_yet_stays_open(): void
    {
        $this->konnectConfigured();
        $payment = $this->pendingPayment();
        $this->fakeDetails(['status' => 'pending', 'amount' => 59500, 'reachedAmount' => 0]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('sent', $this->invoice->fresh()->status);
    }

    /**
     * La référence que Konnect renvoie au client (`?payment_ref=`) doit ouvrir
     * la vérification : c'est la seule qu'il connaisse. Si elle n'ouvre rien,
     * le règlement n'est jamais constaté côté navigateur.
     */
    public function test_the_reference_konnect_sends_back_opens_the_verification(): void
    {
        $this->konnectConfigured();
        $payment = $this->pendingPayment('6f1c2b9e4d3a5c7b8e9f0a1b');
        $this->fakeDetails([
            'status' => 'completed', 'amount' => 59500, 'reachedAmount' => 59500, 'amountDue' => 0,
            'transactions' => [['status' => 'success']],
        ]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->provider_payment_id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    // ── Les paiements Flouci antérieurs restent vérifiables ──────────────────

    /**
     * La bascule ne doit pas emporter les paiements en cours chez l'ancien
     * prestataire. Les vérifier avec la nouvelle passerelle les ferait tous
     * échouer : factures réglées restées impayées, relances, suspensions.
     */
    public function test_a_flouci_payment_is_still_verified_by_flouci_after_the_switch(): void
    {
        $this->konnectConfigured();

        $payment = Payment::create([
            'invoice_id'          => $this->invoice->id,
            'provider'            => 'flouci',
            'provider_payment_id' => 'FLOUCI-ANCIEN-1',
            'status'              => 'pending',
            'amount'              => $this->invoice->total_amount,
            'currency'            => 'TND',
            'expires_at'          => now()->addMinutes(15),
        ]);

        Http::fake(['*verify_payment*' => Http::response(['result' => ['status' => 'SUCCESS']])]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'verify_payment'));
        $this->assertSame('flouci', $this->invoice->fresh()->payment_method);
    }

    // ── Les secrets ne repartent jamais vers le navigateur ───────────────────

    public function test_the_konnect_key_never_travels_back_to_any_screen(): void
    {
        $this->konnectConfigured();

        $admin = $this->actingAs($this->admin)->getJson('/api/v1/admin/platform-settings')->assertOk();
        $admin->assertJsonMissingPath('data.konnect_api_key');

        $public = $this->getJson('/api/v1/public/settings')->assertOk();
        $public->assertJsonMissingPath('data.konnect_api_key');
        $public->assertJsonPath('data.online_payment_enabled', true);
    }

    /** Le drapeau public dit la PRATICABILITÉ, pas l'intention. */
    public function test_the_public_flag_stays_false_while_the_channel_cannot_work(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled' => true, 'konnect_api_key' => null, 'konnect_wallet_id' => null,
            'flouci_enabled'  => false,
        ]);
        config(['konnect.api_key' => '', 'konnect.wallet_id' => '']);

        $this->getJson('/api/v1/public/settings')
            ->assertOk()
            ->assertJsonPath('data.online_payment_enabled', false);
    }
}
