<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chantier A2 — facturation automatique : génération à l'échéance (TVA +
 * timbre paramétrables), relances impayé J+3/7/14, suspension J+21 et
 * réactivation automatique dès paiement confirmé.
 */
class BillingAutomationTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;
    private Organization $org;
    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        $this->billing = app(BillingService::class);

        $this->org = Organization::create([
            'name' => 'KASBAHOST TEST', 'entity_type' => 'company',
            'contact_email' => 'kasba@test.tn', 'status' => 'active',
        ]);
        $this->sub = Subscription::create([
            'organization_id' => $this->org->id,
            'plan_id'         => SubscriptionPlan::where('slug', 'multi-sites')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'custom_price'    => 199,
            'auto_renew'      => true,
            'started_at'      => now()->subMonths(2),
            'expires_at'      => now()->addDays(5),
        ]);
    }

    // ── Génération automatique ───────────────────────────────────────────────

    public function test_renewal_invoice_generated_with_tax_and_timbre(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 19, 'timbre_fiscal' => 1]);

        $created = $this->billing->generateDueRenewalInvoices();

        $this->assertCount(1, $created);
        $invoice = $created[0];
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{4}$/', $invoice->invoice_number);
        $this->assertSame('199.000', (string) $invoice->amount);
        // 19 % de 199 = 37.81 + timbre 1 = 38.81
        $this->assertSame('38.810', (string) $invoice->tax_amount);
        $this->assertSame('237.810', (string) $invoice->total_amount);
        $this->assertSame('sent', $invoice->status);
        $this->assertTrue((bool) ($invoice->metadata['renewal'] ?? false));

        // Idempotent : un second passage ne double pas la facture.
        $this->assertCount(0, $this->billing->generateDueRenewalInvoices());
    }

    public function test_no_renewal_invoice_for_non_auto_renew_or_far_expiry(): void
    {
        $this->sub->update(['auto_renew' => false]);
        $this->assertCount(0, $this->billing->generateDueRenewalInvoices());

        $this->sub->update(['auto_renew' => true, 'expires_at' => now()->addDays(30)]);
        $this->assertCount(0, $this->billing->generateDueRenewalInvoices());
    }

    public function test_invoice_numbering_is_sequential_from_max(): void
    {
        $year = now()->year;
        Invoice::create([
            'subscription_id' => $this->sub->id, 'invoice_number' => "INV-{$year}-0007",
            'amount' => 10, 'tax_amount' => 0, 'total_amount' => 10, 'currency' => 'TND', 'status' => 'paid',
        ]);

        $this->assertSame("INV-{$year}-0008", $this->billing->nextInvoiceNumber());
    }

    // ── Relances + suspension ────────────────────────────────────────────────

    private function overdueInvoice(int $daysLate): Invoice
    {
        return Invoice::create([
            'subscription_id' => $this->sub->id,
            'invoice_number'  => 'INV-2026-0100',
            'amount'          => 199, 'tax_amount' => 0, 'total_amount' => 199,
            'currency'        => 'TND',
            'status'          => 'sent',
            'due_at'          => now()->subDays($daysLate),
        ]);
    }

    public function test_dunning_marks_overdue_and_sends_reminders_once(): void
    {
        $invoice = $this->overdueInvoice(4);

        $stats = $this->billing->runDunning();
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame(1, $stats['reminded']); // J+3 franchi

        $fresh = $invoice->fresh();
        $this->assertSame('overdue', $fresh->status);
        $this->assertSame([3], $fresh->metadata['dunning_sent']);

        // Second passage le même jour : aucune nouvelle relance.
        $stats = $this->billing->runDunning();
        $this->assertSame(0, $stats['reminded']);
    }

    public function test_dunning_suspends_subscription_after_21_days(): void
    {
        $invoice = $this->overdueInvoice(22);

        $stats = $this->billing->runDunning();

        $this->assertSame(1, $stats['suspended']);
        $this->assertSame([3, 7, 14], $invoice->fresh()->metadata['dunning_sent']);

        $sub = $this->sub->fresh();
        $this->assertSame('suspended', $sub->status);
        $this->assertStringContainsString('INV-2026-0100', $sub->suspended_reason);

        // Idempotent : pas de double suspension.
        $this->assertSame(0, $this->billing->runDunning()['suspended']);
    }

    // ── Réactivation automatique dès paiement ────────────────────────────────

    public function test_paying_renewal_invoice_extends_subscription(): void
    {
        PlatformSetting::get()->update(['tax_rate' => 0, 'timbre_fiscal' => 0]);
        $invoice = $this->billing->generateRenewalInvoice($this->sub);
        $oldExpiry = $this->sub->expires_at->copy();

        $invoice->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'virement']);
        $this->billing->handleInvoicePaid($invoice->fresh());

        $sub = $this->sub->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->expires_at->equalTo($oldExpiry->addMonth()), "expires_at devrait être prolongé d'un mois");
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'completed')->count());
    }

    public function test_paying_reactivates_suspended_subscription(): void
    {
        $invoice = $this->overdueInvoice(22);
        $this->billing->runDunning();
        $this->assertSame('suspended', $this->sub->fresh()->status);

        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->actingAs($platformAdmin)
            ->patchJson("/api/v1/admin/hosts/{$this->org->id}/invoices/{$invoice->id}", [
                'status' => 'paid', 'payment_method' => 'virement',
            ])
            ->assertOk();

        $sub = $this->sub->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->suspended_at);
        $this->assertTrue($sub->expires_at->isFuture());
    }

    public function test_validate_virement_reactivates_subscription(): void
    {
        $this->sub->update(['status' => 'expired', 'expires_at' => now()->subDay()]);
        $invoice = Invoice::create([
            'subscription_id' => $this->sub->id, 'invoice_number' => 'INV-2026-0200',
            'amount' => 199, 'tax_amount' => 0, 'total_amount' => 199, 'currency' => 'TND', 'status' => 'sent',
        ]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'provider' => 'virement', 'declared_reference' => 'VIR-1',
            'status' => 'pending', 'amount' => 199, 'currency' => 'TND',
        ]);

        $platformAdmin = User::factory()->platformAdmin()->create();
        $this->actingAs($platformAdmin)
            ->postJson("/api/v1/admin/payments/{$payment->id}/validate-virement")
            ->assertOk();

        $sub = $this->sub->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->expires_at->isFuture());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('completed', $payment->fresh()->status);
    }
}
