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
use App\Services\Billing\BillingService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Un paiement encaissé une fois ne doit produire ses effets qu'une fois.
 *
 * Le déclencheur réel n'a rien d'exotique : au retour de Flouci, le client
 * rafraîchit la page, ou clique deux fois, ou son navigateur rejoue la
 * requête après un timeout. Deux appels de vérification partaient alors en
 * parallèle, chacun voyait un paiement « pending », et chacun déroulait la
 * suite — deux courriels « paiement reçu », deux lignes dans l'historique.
 *
 * La garantie doit être en BASE, pas dans un limiteur de débit : un throttle
 * réduit la fenêtre de course, il ne la ferme pas, et il ne protège pas les
 * autres canaux (virement validé par l'admin, saisie manuelle) qui
 * convergent vers le même point.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $owner;
    private Subscription $sub;

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
        $hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner',
        ]);

        $this->sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subMonth(),
            'expires_at'      => now()->addDay(),
            'auto_renew'      => true,
        ]);
    }

    private function renewalInvoice(): Invoice
    {
        $periodStart = $this->sub->expires_at->copy();

        return Invoice::create([
            'subscription_id' => $this->sub->id,
            'invoice_number'  => 'INV-'.now()->year.'-7001',
            'amount'          => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status'          => 'sent',
            'due_at'          => $periodStart,
            'metadata'        => [
                'renewal'              => true,
                'renewal_period_start' => $periodStart->toIso8601String(),
                'renewal_period_end'   => $periodStart->copy()->addMonth()->toIso8601String(),
            ],
        ]);
    }

    private function settle(Invoice $invoice): void
    {
        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'flouci']);
        app(BillingService::class)->handleInvoicePaid($invoice->fresh(), $this->owner->id);
    }

    // ── Exactement une fois ──────────────────────────────────────────────────

    public function test_settling_the_same_invoice_twice_produces_one_of_everything(): void
    {
        $invoice  = $this->renewalInvoice();
        $expected = $this->sub->expires_at->copy()->addMonth();

        $this->settle($invoice);
        $this->settle($invoice->fresh());
        $this->settle($invoice->fresh());

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count(), 'un seul paiement');
        $this->assertSame(
            1,
            SubscriptionEvent::where('subscription_id', $this->sub->id)
                ->where('event_type', 'payment_confirmed')->count(),
            'un seul évènement de paiement',
        );
        $this->assertSame(
            $expected->toDateString(),
            $this->sub->fresh()->expires_at->toDateString(),
            'la période n\'est prolongée qu\'une fois',
        );
        Mail::assertSentCount(1);
    }

    /**
     * Le verrou est en base, pas en mémoire : la facture porte la marque de
     * son règlement. C'est elle qui ferme la porte aux appels suivants, quel
     * que soit le processus, le conteneur ou le canal d'origine.
     */
    public function test_the_gate_is_recorded_in_the_database(): void
    {
        $invoice = $this->renewalInvoice();
        $this->settle($invoice);

        $marker = DB::table('invoices')->where('id', $invoice->id)
            ->value(DB::raw("metadata->>'payment_settled_at'"));

        $this->assertNotNull($marker, 'le règlement laisse une marque persistante');

        // Un second appel n'écrase pas la marque initiale.
        $this->settle($invoice->fresh());
        $this->assertSame($marker, DB::table('invoices')->where('id', $invoice->id)
            ->value(DB::raw("metadata->>'payment_settled_at'")));
    }

    /** Deux règlements successifs ne réactivent pas deux fois un compte suspendu. */
    public function test_a_suspended_account_is_revived_once_and_only_once(): void
    {
        $this->sub->update(['status' => 'suspended', 'suspended_at' => now(), 'expires_at' => now()->subDays(25)]);
        $invoice = Invoice::create([
            'subscription_id' => $this->sub->id,
            'invoice_number'  => 'INV-'.now()->year.'-7002',
            'amount'          => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status'          => 'sent', 'due_at' => now()->subDays(25),
        ]);

        $this->settle($invoice);
        $after = $this->sub->fresh()->expires_at;

        $this->settle($invoice->fresh());

        $this->assertSame('active', $this->sub->fresh()->status);
        $this->assertNull($this->sub->fresh()->suspended_at);
        $this->assertSame($after->toDateString(), $this->sub->fresh()->expires_at->toDateString());
        Mail::assertSentCount(1);
    }

    /**
     * Le canal Flouci lui-même : deux retours sur la page de succès ne
     * doivent pas déclencher deux fois la suite. Le second appel rend le
     * statut déjà connu, sans rien réexécuter.
     */
    public function test_verifying_a_flouci_payment_twice_settles_it_once(): void
    {
        $invoice = $this->renewalInvoice();

        $payment = Payment::create([
            'invoice_id'          => $invoice->id,
            'provider'            => 'flouci',
            'provider_payment_id' => 'FLOUCI-TEST-1',
            'status'              => 'pending',
            'amount'              => $invoice->total_amount,
            'currency'            => 'TND',
            'expires_at'          => now()->addMinutes(15),
        ]);

        $this->mock(\App\Services\Payment\FlouciService::class, function ($mock) {
            $mock->shouldReceive('verifyPayment')->andReturn(['success' => true, 'raw' => ['status' => 'SUCCESS']]);
        });

        $first  = $this->actingAs($this->owner)->getJson("/api/v1/hotel/payments/{$payment->id}/verify");
        $second = $this->actingAs($this->owner)->getJson("/api/v1/hotel/payments/{$payment->id}/verify");

        $first->assertOk()->assertJsonPath('data.status', 'completed');
        $second->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'completed')->count());
        $this->assertSame(
            1,
            SubscriptionEvent::where('subscription_id', $this->sub->id)
                ->where('event_type', 'payment_confirmed')->count(),
        );
        Mail::assertSentCount(1);
    }

    /**
     * Non-régression : la protection ne doit pas transformer un vrai second
     * paiement (facture suivante) en silence.
     */
    public function test_the_next_invoice_is_still_settled_normally(): void
    {
        $first = $this->renewalInvoice();
        $this->settle($first);

        $second = Invoice::create([
            'subscription_id' => $this->sub->id,
            'invoice_number'  => 'INV-'.now()->year.'-7003',
            'amount'          => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status'          => 'sent', 'due_at' => now()->addMonth(),
            'metadata'        => [
                'renewal'              => true,
                'renewal_period_start' => $this->sub->fresh()->expires_at->toIso8601String(),
                'renewal_period_end'   => $this->sub->fresh()->expires_at->copy()->addMonth()->toIso8601String(),
            ],
        ]);

        $this->settle($second);

        $this->assertSame(2, Payment::whereIn('invoice_id', [$first->id, $second->id])->count());
        Mail::assertSentCount(2);
    }
}
