<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Le rappel serveur de Konnect — ce que Flouci ne savait pas faire.
 *
 * Jusqu'ici, un règlement n'était constaté QUE si le client prenait la peine
 * de revenir sur le site après avoir payé. Un onglet fermé, une connexion
 * coupée au mauvais moment, un retour manqué : la facture restait impayée,
 * partait en relance, puis suspendait un client qui avait pourtant réglé.
 *
 * Konnect prévient le serveur directement. Mais il NE SIGNE PAS ses appels :
 * cette route est publique, et tout ce qu'elle reçoit est suspect. La seule
 * chose qui fasse foi est l'appel sortant qu'elle déclenche.
 */
class KonnectWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'jeton-de-webhook-long-et-aleatoire';

    private Organization $org;
    private User $owner;
    private Subscription $sub;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        PlatformSetting::get()->update([
            'tax_rate'            => 0,
            'timbre_fiscal'       => 0,
            'konnect_enabled'     => true,
            'konnect_environment' => 'sandbox',
            'konnect_api_key'     => 'org:secret',
            'konnect_wallet_id'   => 'portefeuille',
        ]);
        config(['konnect.webhook_token' => self::TOKEN]);
        Mail::fake();

        $this->org = Organization::create([
            'name' => 'DAR OMI', 'entity_type' => 'company',
            'contact_email' => 'dar-omi@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner',
        ]);

        $this->sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonth(), 'expires_at' => now()->addDay(),
            'auto_renew'      => true,
        ]);

        $periodStart = $this->sub->expires_at->copy();

        $this->invoice = Invoice::create([
            'subscription_id' => $this->sub->id,
            'invoice_number'  => 'INV-'.now()->year.'-9101',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'sent', 'due_at' => $periodStart,
            'metadata' => [
                'renewal'              => true,
                'renewal_period_start' => $periodStart->toIso8601String(),
                'renewal_period_end'   => $periodStart->copy()->addMonth()->toIso8601String(),
            ],
        ]);
    }

    private function pendingPayment(string $ref = 'KONNECT-REF-W1'): Payment
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

    private function fakeCompleted(): void
    {
        Http::fake(['*/payments/*' => Http::response(['payment' => [
            'status' => 'completed', 'amount' => 59000, 'reachedAmount' => 59000, 'amountDue' => 0,
            'transactions' => [['status' => 'success']],
        ]])]);
    }

    private function hit(string $ref, string $token = self::TOKEN): \Illuminate\Testing\TestResponse
    {
        return $this->getJson("/api/v1/payments/konnect/webhook/{$token}?payment_ref={$ref}");
    }

    // ── Le règlement est constaté sans le client ─────────────────────────────

    /**
     * LE test de la bascule : le client a payé et n'est jamais revenu. Sans le
     * webhook, cette facture reste impayée jusqu'à la suspension du compte.
     */
    public function test_a_customer_who_never_comes_back_still_gets_their_invoice_settled(): void
    {
        $payment  = $this->pendingPayment();
        $expected = $this->sub->expires_at->copy()->addMonth();
        $this->fakeCompleted();

        $this->hit($payment->provider_payment_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(
            $expected->toDateString(),
            $this->sub->fresh()->expires_at->toDateString(),
            "l'abonnement est prolongé sans que le client soit revenu",
        );
        Mail::assertSentCount(1);
    }

    /** Le rappel n'a pas besoin d'une session : ce n'est pas un utilisateur. */
    public function test_the_webhook_needs_no_authenticated_session(): void
    {
        $payment = $this->pendingPayment();
        $this->fakeCompleted();

        $this->hit($payment->provider_payment_id)->assertOk();

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    /** Konnect peut appeler en POST : le canal ne doit pas dépendre du verbe. */
    public function test_the_webhook_also_answers_a_post(): void
    {
        $payment = $this->pendingPayment();
        $this->fakeCompleted();

        $this->postJson('/api/v1/payments/konnect/webhook/'.self::TOKEN, [
            'payment_ref' => $payment->provider_payment_id,
        ])->assertOk();

        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    // ── Un seul encaissement, quel que soit le nombre de chemins ─────────────

    /**
     * Le webhook et le retour navigateur arrivent tous les deux, dans un ordre
     * quelconque. Deux e-mails « paiement reçu » et deux prolongations d'un
     * mois seraient un cadeau involontaire.
     */
    public function test_the_webhook_and_the_browser_return_settle_the_invoice_exactly_once(): void
    {
        $payment  = $this->pendingPayment();
        $expected = $this->sub->expires_at->copy()->addMonth();
        $this->fakeCompleted();

        $this->hit($payment->provider_payment_id)->assertOk();
        $this->actingAs($this->owner)
            ->getJson("/api/v1/hotel/payments/{$payment->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, Payment::where('invoice_id', $this->invoice->id)->where('status', 'completed')->count());
        $this->assertSame(
            1,
            SubscriptionEvent::where('subscription_id', $this->sub->id)->where('event_type', 'payment_confirmed')->count(),
        );
        $this->assertSame($expected->toDateString(), $this->sub->fresh()->expires_at->toDateString());
        Mail::assertSentCount(1);
    }

    /** L'ordre inverse doit donner exactement le même résultat. */
    public function test_the_browser_return_then_the_webhook_settle_the_invoice_exactly_once(): void
    {
        $payment = $this->pendingPayment();
        $this->fakeCompleted();

        $this->actingAs($this->owner)->getJson("/api/v1/hotel/payments/{$payment->id}/verify")->assertOk();
        $this->hit($payment->provider_payment_id)->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(
            1,
            SubscriptionEvent::where('subscription_id', $this->sub->id)->where('event_type', 'payment_confirmed')->count(),
        );
        Mail::assertSentCount(1);
    }

    /** Konnect rejoue ses rappels après une coupure : deux appels, un effet. */
    public function test_a_replayed_webhook_changes_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->fakeCompleted();

        $this->hit($payment->provider_payment_id)->assertOk();
        $this->hit($payment->provider_payment_id)->assertOk()->assertJsonPath('data.status', 'completed');

        Mail::assertSentCount(1);
        $this->assertSame(1, Payment::where('invoice_id', $this->invoice->id)->where('status', 'completed')->count());
    }

    // ── Rien de ce que porte la requête n'est cru ────────────────────────────

    /**
     * Le cœur du dispositif : la route est publique et Konnect ne signe pas.
     * Un appel qui AFFIRME un paiement réussi ne doit rien encaisser tant que
     * Konnect lui-même ne le confirme pas.
     */
    public function test_a_webhook_claiming_a_success_settles_nothing_when_the_gateway_disagrees(): void
    {
        $payment = $this->pendingPayment();
        Http::fake(['*/payments/*' => Http::response(['payment' => [
            'status' => 'pending', 'amount' => 59000, 'reachedAmount' => 0,
        ]])]);

        $this->getJson(
            '/api/v1/payments/konnect/webhook/'.self::TOKEN
            .'?payment_ref='.$payment->provider_payment_id.'&status=completed&amount=59000'
        )->assertOk()->assertJsonPath('data.status', 'pending');

        $this->assertSame('sent', $this->invoice->fresh()->status);
        $this->assertSame('pending', $payment->fresh()->status);
        Mail::assertNothingSent();
    }

    // ── La porte ────────────────────────────────────────────────────────────

    public function test_a_wrong_token_opens_nothing_and_calls_nobody(): void
    {
        $payment = $this->pendingPayment();
        Http::fake();

        $this->hit($payment->provider_payment_id, 'mauvais-jeton')->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('sent', $this->invoice->fresh()->status);
    }

    /**
     * Sans jeton configuré, la route est FERMÉE — jamais ouverte par défaut.
     * C'est ce qui empêche un déploiement incomplet d'exposer un encaissement
     * à qui devine une référence.
     */
    public function test_an_unconfigured_webhook_is_closed(): void
    {
        config(['konnect.webhook_token' => '']);
        $payment = $this->pendingPayment();
        Http::fake();

        $this->getJson("/api/v1/payments/konnect/webhook/?payment_ref={$payment->provider_payment_id}")
            ->assertNotFound();
        $this->hit($payment->provider_payment_id, 'nimporte-quoi')->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_an_unknown_reference_is_simply_not_found(): void
    {
        Http::fake();

        $this->hit('REFERENCE-INCONNUE')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_webhook_without_any_reference_is_not_found(): void
    {
        Http::fake();

        $this->getJson('/api/v1/payments/konnect/webhook/'.self::TOKEN)->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * Une panne de vérification doit rendre une erreur : c'est le seul cas où
     * l'on VEUT que Konnect réessaie. Répondre 200 perdrait l'encaissement.
     */
    public function test_a_verification_outage_asks_konnect_to_retry(): void
    {
        $payment = $this->pendingPayment();
        Http::fake(['*/payments/*' => Http::response('', 500)]);

        $this->hit($payment->provider_payment_id)->assertStatus(502);

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
