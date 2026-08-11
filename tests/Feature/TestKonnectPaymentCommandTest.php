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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Le banc d'essai du parcours de paiement.
 *
 * Ce qu'il protège tient en une phrase : un essai ne doit rien faire subir aux
 * vrais clients. La relance globale (`invoices:dunning`) enverrait de vraies
 * relances et suspendrait de vrais comptes ; cette commande ne touche qu'à ce
 * qu'on lui désigne, et sait reprendre ce qu'elle a créé.
 */
class TestKonnectPaymentCommandTest extends TestCase
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
            'name' => 'UW Agency', 'entity_type' => 'company',
            'contact_email' => 'facturation@uw.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $hotel = Hotel::factory()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->hotelAdmin($hotel)->create([
            'organization_id' => $this->org->id, 'role_org' => 'owner', 'email' => 'patron@uw.tn',
        ]);

        $this->sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonth(), 'expires_at' => now()->addDays(20),
            'auto_renew'      => true,
        ]);
    }

    private function konnectConfigured(): void
    {
        PlatformSetting::get()->update([
            'konnect_enabled'     => true,
            'konnect_environment' => 'sandbox',
            'konnect_api_key'     => 'org:secret',
            'konnect_wallet_id'   => 'portefeuille',
        ]);
    }

    public function test_it_creates_a_marked_test_invoice_on_the_named_account(): void
    {
        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--amount' => 1])
            ->assertSuccessful();

        $invoice = Invoice::where('subscription_id', $this->sub->id)->firstOrFail();
        $this->assertSame('1.000', (string) $invoice->total_amount);
        $this->assertSame('sent', $invoice->status);
        $this->assertTrue($invoice->metadata['test_payment']);
    }

    /** Antidatée, la facture arrive dans l'état où le planificateur l'aurait mise. */
    public function test_a_backdated_invoice_is_already_overdue(): void
    {
        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--days-late' => 3])
            ->assertSuccessful();

        $invoice = Invoice::where('subscription_id', $this->sub->id)->firstOrFail();
        $this->assertSame('overdue', $invoice->status);
    }

    /**
     * La relance part à l'adresse de facturation de l'organisation — et à elle
     * seule. C'est tout l'intérêt d'une porte étroite : `invoices:dunning`
     * aurait balayé le portefeuille entier.
     */
    public function test_the_reminder_targets_only_the_named_account(): void
    {
        $autreOrg = Organization::create([
            'name' => 'AUTRE MAISON', 'entity_type' => 'company',
            'contact_email' => 'autre@test.tn', 'status' => 'active', 'locale' => 'fr',
        ]);
        $autreSub = Subscription::create([
            'organization_id' => $autreOrg->id,
            'plan_id'         => (int) SubscriptionPlan::where('slug', 'essentiel')->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonths(2), 'expires_at' => now()->subDays(30),
            'auto_renew'      => true,
        ]);
        // Une vraie facture en retard, chez quelqu'un d'autre : elle ne doit pas
        // bouger d'un pouce.
        $leur = Invoice::create([
            'subscription_id' => $autreSub->id, 'invoice_number' => 'INV-'.now()->year.'-4242',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'overdue', 'due_at' => now()->subDays(25),
        ]);

        $this->artisan('qayed:test-payment', [
            'email' => 'patron@uw.tn', '--days-late' => 3, '--remind' => true,
        ])->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame('overdue', $leur->fresh()->status);
        $this->assertSame('active', $autreSub->fresh()->status, "le compte d'un tiers n'est pas suspendu");
        $this->assertArrayNotHasKey('dunning_sent', $leur->fresh()->metadata ?? []);
    }

    public function test_it_opens_a_real_payment_session_and_records_it(): void
    {
        $this->konnectConfigured();
        Http::fake(['*init-payment*' => Http::response([
            'payUrl' => 'https://gateway.sandbox.konnect.network/pay?payment_ref=REF-CLI',
            'paymentRef' => 'REF-CLI',
        ])]);

        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--pay' => true])
            ->expectsOutputToContain('REF-CLI')
            ->assertSuccessful();

        $payment = Payment::firstOrFail();
        $this->assertSame('konnect', $payment->provider);
        $this->assertSame('pending', $payment->status);
        // Le montant part en millimes, comme depuis l'application.
        Http::assertSent(fn ($request) => $request['amount'] === 1000);
    }

    /** Canal fermé : on le dit, on n'appelle personne. */
    public function test_it_refuses_to_pay_when_the_channel_is_closed(): void
    {
        Http::fake();

        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--pay' => true])
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, Payment::count());
    }

    /** L'essai se reprend entièrement, et ne touche qu'à lui-même. */
    public function test_cleanup_removes_only_what_the_command_created(): void
    {
        $vraie = Invoice::create([
            'subscription_id' => $this->sub->id, 'invoice_number' => 'INV-'.now()->year.'-0001',
            'amount' => 59, 'tax_amount' => 0, 'total_amount' => 59, 'currency' => 'TND',
            'status' => 'sent', 'due_at' => now()->addDays(5),
        ]);

        $this->konnectConfigured();
        Http::fake(['*init-payment*' => Http::response([
            'payUrl' => 'https://gateway.sandbox.konnect.network/pay?payment_ref=REF-CLI',
            'paymentRef' => 'REF-CLI',
        ])]);
        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--pay' => true])->assertSuccessful();

        $this->artisan('qayed:test-payment', ['email' => 'patron@uw.tn', '--cleanup' => true])
            ->assertSuccessful();

        $this->assertSame(0, Payment::count());
        $this->assertNotNull($vraie->fresh(), 'la facture réelle survit');
        $this->assertSame(1, Invoice::where('subscription_id', $this->sub->id)->count());
    }

    public function test_an_unknown_address_is_reported_not_guessed(): void
    {
        $this->artisan('qayed:test-payment', ['email' => 'personne@nulle-part.tn'])
            ->assertFailed();

        $this->assertSame(0, Invoice::count());
    }
}
