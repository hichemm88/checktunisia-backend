<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Règle métier §1.3 : « Une facture payée = un paiement visible dans
 * l'historique, toujours. » La transition d'une facture vers « paid » (quel
 * que soit le chemin) doit laisser une trace dans la table payments.
 */
class InvoicePaymentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;
    private Organization $org;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        $this->platformAdmin = User::factory()->platformAdmin()->create();

        $this->org = Organization::create([
            'name' => 'KASBAHOST TEST', 'entity_type' => 'company',
            'contact_email' => 'kasba@test.tn', 'status' => 'active',
        ]);
        $this->sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => SubscriptionPlan::where('slug', 'multi-sites')->value('id')
                                  ?? SubscriptionPlan::first()->id,
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'custom_price'    => 199,
            'started_at'      => now()->subMonth(),
            'expires_at'      => now()->addMonth(),
        ]);
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'subscription_id' => $this->sub->id,
            'hotel_id'        => null,
            'invoice_number'  => 'INV-2026-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'amount'          => 199,
            'tax_amount'      => 19,
            'total_amount'    => 218,
            'currency'        => 'TND',
            'status'          => 'sent',
        ], $overrides));
    }

    public function test_marking_invoice_paid_creates_a_completed_payment(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", [
                'status'            => 'paid',
                'paid_at'           => now()->toDateTimeString(),
                'payment_method'    => 'virement',
                'payment_reference' => 'VIR-BNA-12345',
            ])
            ->assertOk();

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment, 'Une facture payée doit créer un paiement dans l’historique.');
        $this->assertSame('completed', $payment->status);
        $this->assertSame('virement', $payment->provider);
        $this->assertSame('VIR-BNA-12345', $payment->declared_reference);
        $this->assertSame('218.000', (string) $payment->amount);
    }

    public function test_marking_paid_completes_existing_pending_virement_instead_of_duplicating(): void
    {
        $invoice = $this->makeInvoice();
        $pending = Payment::create([
            'invoice_id'         => $invoice->id,
            'hotel_id'           => null,
            'provider'           => 'virement',
            'declared_reference' => 'VIR-DECLARE-1',
            'status'             => 'pending',
            'amount'             => 218,
            'currency'           => 'TND',
        ]);

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertOk();

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('completed', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->completed_at);
    }

    public function test_repeated_updates_do_not_duplicate_payments(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertOk();
        // Retour à sent puis re-paid (correction admin) : pas de doublon.
        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", ['status' => 'sent'])
            ->assertOk();
        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", ['status' => 'paid'])
            ->assertOk();

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'completed')->count());
    }

    public function test_payment_appears_in_admin_ledger(): void
    {
        $invoice = $this->makeInvoice();

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", [
                'status' => 'paid', 'payment_method' => 'virement',
            ])
            ->assertOk();

        $ledger = $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($ledger);
        $this->assertSame($invoice->invoice_number, $ledger[0]['invoice_number']);
        $this->assertSame('KASBAHOST TEST', $ledger[0]['hotel_name']);
    }
}
