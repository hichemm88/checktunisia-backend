<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\AuditLog;
use App\Models\EstablishmentPartnerLink;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Subscription\ApiAccessGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * LOT 5 — garde-fous des comptes dont l'accès API partenaire est en service.
 *
 *  1. alerte ADMIN à J-15 de l'échéance, comptes internes compris,
 *     idempotente par (abonnement, échéance) ;
 *  2. aucune suspension/expiration automatique pour impayé : retenue au
 *     niveau du service (BillingService, ApiAccessGuard) et remplacée par
 *     une alerte admin unique.
 */
class ApiAccessSubscriptionGuardTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->platformAdmin = User::factory()->platformAdmin()->create();
    }

    /** Compte avec accès API en service (pack api_access + liaison active). */
    private function apiAccount(array $subAttrs = [], string $billingMode = 'commercial', bool $apiAccess = true, bool $linked = true): array
    {
        $fixture = $this->makeOrgWithHotel($apiAccess);
        $fixture['org']->update(['billing_mode' => $billingMode]);
        $fixture['subscription']->update(array_merge(['auto_renew' => true], $subAttrs));
        $fixture['partner'] = $this->makePartner();
        if ($linked) {
            $fixture['link'] = $this->linkEstablishment($fixture['partner'], $fixture['hotel']);
        }
        $fixture['subscription'] = $fixture['subscription']->fresh();

        return $fixture;
    }

    private function adminMails(string $needle): int
    {
        return Mail::sent(SystemMail::class, fn (SystemMail $m) => str_starts_with($m->renderedSubject, '[Qayed Admin]')
            && str_contains($m->renderedSubject, $needle)
            && $m->hasTo($this->platformAdmin->email))->count();
    }

    private function overdueInvoice(Subscription $sub, int $daysLate): Invoice
    {
        return Invoice::create([
            'subscription_id' => $sub->id,
            'invoice_number' => 'INV-2026-'.random_int(1000, 9999),
            'amount' => 199, 'tax_amount' => 0, 'total_amount' => 199,
            'currency' => 'TND',
            'status' => 'sent',
            'due_at' => now()->subDays($daysLate),
        ]);
    }

    // ── 1. Alerte J-15 ───────────────────────────────────────────────────────

    public function test_admin_is_alerted_once_15_days_before_expiry_of_an_api_account(): void
    {
        ['subscription' => $sub] = $this->apiAccount(['expires_at' => now()->addDays(15)]);

        $this->artisan('subscriptions:alert-api-expiring')->assertExitCode(0);
        $this->assertSame(1, $this->adminMails('Échéance'));
        $this->assertSame(1, AuditLog::where('action', 'subscription.api_expiry_alert_sent')->where('subject_id', $sub->id)->count());

        // Idempotent : relancé (même jour ou suivants), pas de second envoi.
        $this->artisan('subscriptions:alert-api-expiring');
        $this->travel(3)->days();
        $this->artisan('subscriptions:alert-api-expiring');
        $this->assertSame(1, $this->adminMails('Échéance'));

        // Nouvelle échéance (prolongation) → nouvelle alerte le moment venu.
        $sub->fresh()->update(['expires_at' => now()->addDays(10)]);
        $this->artisan('subscriptions:alert-api-expiring');
        $this->assertSame(2, $this->adminMails('Échéance'));
    }

    public function test_internal_account_is_alerted_too(): void
    {
        ['subscription' => $sub] = $this->apiAccount(['expires_at' => now()->addDays(7)], billingMode: 'internal');
        $this->assertTrue($sub->isInternal());

        $this->assertSame(1, ApiAccessGuard::alertUpcomingExpiries());
        $this->assertSame(1, $this->adminMails('Échéance'));
    }

    public function test_no_alert_without_active_api_access_or_outside_the_window(): void
    {
        $this->apiAccount(['expires_at' => now()->addDays(20)]);                       // trop tôt
        $this->apiAccount(['expires_at' => now()->addDays(5)], apiAccess: false);       // pas d'option API
        $this->apiAccount(['expires_at' => now()->addDays(5)], linked: false);          // option, aucune liaison
        $revoked = $this->apiAccount(['expires_at' => now()->addDays(5)]);
        $revoked['link']->update(['revoked_at' => now()]);                                // liaison révoquée
        $suspendedPartner = $this->apiAccount(['expires_at' => now()->addDays(5)]);
        $suspendedPartner['partner']->update(['status' => 'suspended']);                 // partenaire suspendu

        $this->assertSame(0, ApiAccessGuard::alertUpcomingExpiries());
        $this->assertSame(0, $this->adminMails('Échéance'));
    }

    public function test_alert_command_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'subscriptions:alert-api-expiring'));

        $this->assertCount(1, $events);
    }

    // ── 2. Pas de suspension automatique pour impayé ────────────────────────

    public function test_dunning_never_suspends_an_api_account_and_alerts_admin_once(): void
    {
        ['subscription' => $sub] = $this->apiAccount();
        $invoice = $this->overdueInvoice($sub, 22);
        $billing = app(BillingService::class);

        $stats = $billing->runDunning();

        $this->assertSame(0, $stats['suspended']);
        $this->assertSame(1, $stats['withheld']);
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame([3, 7, 14], $invoice->fresh()->metadata['dunning_sent'], 'les relances client partent normalement');
        $this->assertTrue($invoice->fresh()->metadata['dunning_suspension_withheld']);
        $this->assertSame(1, $this->adminMails('Suspension automatique retenue'));
        $this->assertSame(1, AuditLog::where('action', 'subscription.auto_suspension_withheld')->count());

        // Passages suivants : toujours pas de coupure, pas de nouvelle alerte.
        $billing->runDunning();
        $this->travel(2)->days();
        $billing->runDunning();
        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame(1, $this->adminMails('Suspension automatique retenue'));
    }

    public function test_dunning_still_suspends_once_api_access_is_no_longer_in_service(): void
    {
        ['subscription' => $sub, 'link' => $link] = $this->apiAccount();
        $this->overdueInvoice($sub, 22);
        $billing = app(BillingService::class);

        $billing->runDunning();
        $this->assertSame('active', $sub->fresh()->status);

        $link->update(['revoked_at' => now()]);
        $stats = $billing->runDunning();

        $this->assertSame(1, $stats['suspended']);
        $this->assertSame('suspended', $sub->fresh()->status);
    }

    public function test_dunning_suspends_a_regular_account_as_before(): void
    {
        ['subscription' => $sub] = $this->apiAccount(linked: false);
        $this->overdueInvoice($sub, 22);

        $stats = app(BillingService::class)->runDunning();

        $this->assertSame(1, $stats['suspended']);
        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertSame(0, $this->adminMails('Suspension automatique retenue'));
    }

    public function test_expire_overdue_withholds_expiry_of_an_unpaid_api_account_after_grace(): void
    {
        ['subscription' => $sub] = $this->apiAccount([
            'expires_at' => now()->subDays(BillingService::DUNNING_SUSPEND_DAYS + 1),
        ]);

        $this->artisan('subscriptions:expire-overdue')->assertExitCode(0);
        $this->artisan('subscriptions:expire-overdue');

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame(1, $this->adminMails('Suspension automatique retenue'));
    }

    public function test_expire_overdue_still_expires_regular_and_cancelled_api_accounts(): void
    {
        $past = now()->subDays(BillingService::DUNNING_SUSPEND_DAYS + 1);
        ['subscription' => $regular] = $this->apiAccount(['expires_at' => $past], linked: false);
        // Résiliation choisie par le client : pas un impayé, l'expiration suit son cours.
        ['subscription' => $cancelled] = $this->apiAccount(['expires_at' => now()->subDay(), 'auto_renew' => false]);

        $this->artisan('subscriptions:expire-overdue');

        $this->assertSame('expired', $regular->fresh()->status);
        $this->assertSame('expired', $cancelled->fresh()->status);
        $this->assertSame(0, $this->adminMails('Suspension automatique retenue'));
    }

    public function test_api_access_detection_uses_the_subscription_itself_even_once_past_expiry(): void
    {
        ['subscription' => $sub] = $this->apiAccount(['expires_at' => now()->subMonth(), 'status' => 'expired']);

        // L'organisation n'a plus d'abonnement « actif », mais l'abonnement
        // examiné, lui, accorde api_access : la garde doit le voir.
        $this->assertTrue(ApiAccessGuard::isActive($sub));
        $this->assertSame(1, EstablishmentPartnerLink::count());
    }
}
