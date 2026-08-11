<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un canal de paiement ANNONCÉ doit être un canal PRATICABLE.
 *
 * Les deux moyens de règlement sont pilotés depuis le back-office. Rien
 * n'imposait jusqu'ici que le pilotage corresponde à la réalité :
 *
 *  - Flouci exposait « App Token » et « App Secret » dans l'écran Paiements,
 *    les enregistrait dans `platform_settings`… et le service ne les lisait
 *    JAMAIS : il n'interrogeait que les variables d'environnement. Un
 *    exploitant qui configurait la passerelle par le chemin prévu obtenait un
 *    canal ouvert dont chaque paiement échouait en « Service de paiement
 *    indisponible, réessayez dans quelques instants » — une panne définitive
 *    annoncée comme passagère.
 *
 *  - Le virement pouvait être activé sans bénéficiaire ni compte : le client
 *    recevait un formulaire de déclaration sans savoir où envoyer l'argent.
 *
 * Le virement étant aujourd'hui le seul canal réellement ouvert en
 * production, une configuration muette n'est pas un détail d'ergonomie :
 * c'est un client qui ne peut pas payer.
 */
class PaymentChannelConfigTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $owner;
    private Invoice $invoice;
    private User $admin;

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
            'subscription_id' => $sub->id, 'invoice_number' => 'INV-'.now()->year.'-8001',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'sent', 'due_at' => now()->addDays(7),
        ]);
    }

    /** Réglages d'un exploitant qui a tout renseigné correctement. */
    private function fullyConfigured(array $overrides = []): void
    {
        PlatformSetting::get()->update(array_merge([
            'flouci_enabled'       => true,
            'flouci_app_token'     => 'TOKEN-SAISI-EN-BACK-OFFICE',
            'flouci_app_secret'    => 'SECRET-SAISI-EN-BACK-OFFICE',
            'virement_enabled'     => true,
            'virement_beneficiary' => 'Bénéficiaire Officiel',
            'virement_iban'        => 'TN5910006035010054930010',
        ], $overrides));
    }

    private function initiate(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)
            ->postJson('/api/v1/hotel/payments/initiate', ['invoice_id' => $this->invoice->id]);
    }

    // ── Les identifiants du back-office doivent être ceux réellement utilisés ──

    public function test_the_credentials_entered_in_the_back_office_are_the_ones_actually_sent_to_flouci(): void
    {
        $this->fullyConfigured();

        // L'environnement porte d'AUTRES valeurs : c'est la saisie du
        // back-office qui doit gagner, sinon l'écran ne sert à rien.
        config([
            'flouci.app_token'  => 'jeton-environnement',
            'flouci.app_secret' => 'secret-environnement',
        ]);

        Http::fake(['*generate_payment*' => Http::response([
            'result' => ['success' => true, 'paymentId' => 'FLOUCI-1', 'link' => 'https://flouci.test/pay/1'],
        ])]);

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request['app_token'] === 'TOKEN-SAISI-EN-BACK-OFFICE'
            && $request['app_secret'] === 'SECRET-SAISI-EN-BACK-OFFICE');
    }

    public function test_the_environment_still_provides_the_credentials_when_the_back_office_has_none(): void
    {
        PlatformSetting::get()->update([
            'flouci_enabled'    => true,
            'flouci_app_token'  => null,
            'flouci_app_secret' => null,
        ]);
        config([
            'flouci.app_token'  => 'jeton-environnement',
            'flouci.app_secret' => 'secret-environnement',
        ]);

        Http::fake(['*generate_payment*' => Http::response([
            'result' => ['success' => true, 'paymentId' => 'FLOUCI-2', 'link' => 'https://flouci.test/pay/2'],
        ])]);

        $this->initiate()->assertCreated();

        Http::assertSent(fn ($request) => $request['app_token'] === 'jeton-environnement');
    }

    // ── Un canal fermé se dit fermé, il ne se casse pas ────────────────────────

    public function test_a_closed_online_channel_is_announced_as_such_and_never_calls_the_gateway(): void
    {
        PlatformSetting::get()->update(['flouci_enabled' => false]);
        Http::fake();

        $this->initiate()
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'PAYMENT_METHOD_UNAVAILABLE');

        Http::assertNothingSent();
    }

    /**
     * Le cas qui a produit la panne : le drapeau est levé, les identifiants
     * manquent. Le client doit lire « ce canal n'est pas ouvert », pas
     * « réessayez dans quelques instants ».
     */
    public function test_an_online_channel_switched_on_without_credentials_is_treated_as_closed(): void
    {
        PlatformSetting::get()->update([
            'flouci_enabled'    => true,
            'flouci_app_token'  => null,
            'flouci_app_secret' => null,
        ]);
        config(['flouci.app_token' => '', 'flouci.app_secret' => '']);
        Http::fake();

        $this->initiate()
            ->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'PAYMENT_METHOD_UNAVAILABLE');

        Http::assertNothingSent();
    }

    // ── On n'ouvre pas un canal qu'on n'a pas configuré ────────────────────────

    public function test_switching_on_the_online_channel_without_credentials_is_refused(): void
    {
        PlatformSetting::get()->update(['flouci_app_token' => null, 'flouci_app_secret' => null]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', ['flouci_enabled' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_CHANNEL_INCOMPLETE');

        $this->assertFalse((bool) PlatformSetting::get()->fresh()->flouci_enabled);
    }

    /**
     * Note sur le point de départ des deux tests suivants : ils OUVRENT le
     * canal, ils partent donc d'un canal fermé.
     *
     * La ligne de réglages livrée par défaut ouvre le virement avec un
     * bénéficiaire mais sans compte — un état déjà incomplet. Partir de là ne
     * testait pas l'ouverture d'un canal muet mais l'enregistrement d'un
     * défaut préexistant, ce qui verrouillait l'écran entier au lieu de garder
     * une porte (voir `incompleteChannel()`).
     */
    public function test_switching_on_the_bank_transfer_without_a_beneficiary_is_refused(): void
    {
        PlatformSetting::get()->update(['virement_enabled' => false]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'virement_enabled'     => true,
                'virement_beneficiary' => '',
                'virement_iban'        => 'TN5910006035010054930010',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_CHANNEL_INCOMPLETE');
    }

    public function test_switching_on_the_bank_transfer_without_an_account_is_refused(): void
    {
        PlatformSetting::get()->update(['virement_enabled' => false]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', [
                'virement_enabled'     => true,
                'virement_beneficiary' => 'Bénéficiaire Officiel',
                'virement_iban'        => '',
                'virement_rib'         => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PAYMENT_CHANNEL_INCOMPLETE');
    }

    /**
     * Le garde ne doit jamais bloquer un exploitant déjà en règle — c'est la
     * situation de la production, qui doit rester modifiable.
     */
    public function test_a_correctly_configured_operator_can_still_save_its_settings(): void
    {
        $this->fullyConfigured();

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', ['tax_rate' => 19])
            ->assertOk();

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', ['virement_bank_name' => 'Banque Nationale Agricole BNA'])
            ->assertOk();

        $this->assertSame('Banque Nationale Agricole BNA', PlatformSetting::get()->fresh()->virement_bank_name);
    }

    /** Fermer un canal ne demande évidemment aucune configuration. */
    public function test_switching_a_channel_off_never_requires_configuration(): void
    {
        PlatformSetting::get()->update(['virement_beneficiary' => null, 'virement_iban' => null, 'virement_rib' => null]);

        $this->actingAs($this->admin)
            ->patchJson('/api/v1/admin/platform-settings', ['virement_enabled' => false])
            ->assertOk();
    }

    // ── Les secrets ne repartent jamais vers le navigateur ─────────────────────

    public function test_the_gateway_secrets_never_travel_back_to_any_screen(): void
    {
        $this->fullyConfigured();

        $admin = $this->actingAs($this->admin)->getJson('/api/v1/admin/platform-settings')->assertOk();
        $admin->assertJsonMissingPath('data.flouci_app_token');
        $admin->assertJsonMissingPath('data.flouci_app_secret');

        $public = $this->getJson('/api/v1/public/settings')->assertOk();
        $public->assertJsonMissingPath('data.flouci_app_token');
        $public->assertJsonMissingPath('data.flouci_app_secret');
    }
}
