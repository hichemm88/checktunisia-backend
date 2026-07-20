<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Email\SystemMailer;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Chantier A2 — cycle de facturation automatique.
 *
 * Source de vérité unique pour :
 *  - la numérotation séquentielle INV-AAAA-NNNN (sans collision après
 *    suppression : max existant + 1, pas count + 1) ;
 *  - la génération de la facture de renouvellement à l'échéance (TVA et
 *    timbre fiscal depuis les réglages plateforme) + email « Facture
 *    disponible » ;
 *  - les relances impayé J+3 / J+7 / J+14 après due_at et la suspension
 *    automatique à J+21 ;
 *  - la confirmation d'un paiement (quel que soit le canal : Flouci,
 *    virement validé, saisie admin) : trace dans l'historique des
 *    paiements, réactivation/prolongation de l'abonnement, email
 *    « Paiement reçu ».
 *
 * Règle transverse : toute transition écrit dans le Journal d'activité et
 * dans subscription_events.
 */
class BillingService
{
    /** Jours après due_at auxquels une relance est envoyée. */
    public const DUNNING_REMINDER_DAYS = [3, 7, 14];

    /** Jours après due_at au bout desquels l'abonnement est suspendu. */
    public const DUNNING_SUSPEND_DAYS = 21;

    /** Fenêtre avant échéance dans laquelle la facture de renouvellement est émise. */
    public const RENEWAL_WINDOW_DAYS = 7;

    // ─── Numérotation ────────────────────────────────────────────────────────

    /** Prochain numéro séquentiel INV-AAAA-NNNN (max existant + 1). */
    public function nextInvoiceNumber(): string
    {
        $year = now()->year;
        $max = Invoice::where('invoice_number', 'like', "INV-{$year}-%")
            ->get(['invoice_number'])
            ->map(fn($i) => (int) substr($i->invoice_number, -4))
            ->max() ?? 0;

        return sprintf('INV-%d-%04d', $year, $max + 1);
    }

    // ─── Génération automatique à l'échéance ─────────────────────────────────

    /**
     * Génère les factures de renouvellement des abonnements payants en
     * auto-renouvellement arrivant à échéance sous RENEWAL_WINDOW_DAYS,
     * sauf si une facture de renouvellement ouverte existe déjà.
     *
     * @return Invoice[] factures créées
     */
    public function generateDueRenewalInvoices(): array
    {
        $subs = Subscription::with(['plan', 'organization', 'hotel'])
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereBetween('expires_at', [now(), now()->addDays(self::RENEWAL_WINDOW_DAYS)])
            ->get();

        $created = [];
        foreach ($subs as $sub) {
            try {
                $invoice = $this->generateRenewalInvoice($sub);
                if ($invoice) {
                    $created[] = $invoice;
                }
            } catch (\Throwable $e) {
                Log::error("[billing] renewal invoice failed for subscription {$sub->id}: ".$e->getMessage());
            }
        }

        return $created;
    }

    /** Facture de renouvellement pour un abonnement donné (null si une facture ouverte existe déjà). */
    public function generateRenewalInvoice(Subscription $sub): ?Invoice
    {
        $periodStart = $sub->expires_at->copy();
        $periodEnd   = $sub->billing_cycle === 'yearly'
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();

        // Déjà facturé pour cette période (ou facture encore ouverte) ? On ne double pas.
        $open = Invoice::where('subscription_id', $sub->id)
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->exists();
        $samePeriod = Invoice::where('subscription_id', $sub->id)
            ->where('metadata->renewal_period_start', $periodStart->toIso8601String())
            ->exists();
        if ($open || $samePeriod) {
            return null;
        }

        $settings = PlatformSetting::get();
        // Formule unique : base + suppléments par établissement (config en
        // base), prix négocié prioritaire. Voir PlanPricing.
        $pricing = \App\Services\Subscription\PlanPricing::detail($sub);
        $amount  = $pricing['cycle_total'];
        if ($amount <= 0) {
            return null;
        }

        $tax = round($amount * ((float) $settings->tax_rate) / 100, 3) + (float) $settings->timbre_fiscal;

        $invoice = Invoice::create([
            'subscription_id' => $sub->id,
            'hotel_id'        => null,
            'invoice_number'  => $this->nextInvoiceNumber(),
            'amount'          => $amount,
            'tax_amount'      => $tax,
            'total_amount'    => $amount + $tax,
            'currency'        => 'TND',
            'status'          => 'sent',
            'due_at'          => $sub->expires_at,
            'notes'           => 'Renouvellement '.($sub->billing_cycle === 'yearly' ? 'annuel' : 'mensuel')
                .' — période du '.$periodStart->format('d/m/Y').' au '.$periodEnd->format('d/m/Y')
                .($pricing['extra_count'] > 0 && !$pricing['negotiated']
                    ? sprintf(' · base %s + %d établissement(s) suppl. × %s TND',
                        number_format($pricing['base'], 3, '.', ''), $pricing['extra_count'],
                        number_format((float) $pricing['extra_property_price'], 3, '.', ''))
                    : ''),
            'metadata'        => [
                'renewal'              => true,
                'renewal_period_start' => $periodStart->toIso8601String(),
                'renewal_period_end'   => $periodEnd->toIso8601String(),
                'tax_rate'             => (string) $settings->tax_rate,
                'timbre_fiscal'        => (string) $settings->timbre_fiscal,
                'pricing'              => $pricing,
            ],
        ]);

        AuditLogger::log('invoice.auto_generated', $invoice, newValues: [
            'invoice_number' => $invoice->invoice_number,
            'total_amount'   => (string) $invoice->total_amount,
        ]);

        $org    = $sub->organization;
        $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;
        SystemMailer::send('invoice_available', $org?->contact_email, [
            'name'            => $org?->name ?? $sub->hotel?->name ?? 'Client Qayed',
            'plan_name'       => $sub->plan?->name ?? '—',
            'invoice_number'  => $invoice->invoice_number,
            'credentials_box' => SystemMailer::amountBox(Money::tnd($invoice->total_amount, $invoice->currency), $invoice->invoice_number, $locale),
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('view_invoice', $locale)),
        ], $locale);

        return $invoice;
    }

    // ─── Relances impayé + suspension ────────────────────────────────────────

    /**
     * Passe les factures échues en « overdue », envoie les relances J+3/7/14
     * et suspend l'abonnement à J+21. Idempotent : chaque relance n'est
     * envoyée qu'une fois (trace dans invoice.metadata.dunning_sent).
     *
     * @return array{overdue:int,reminded:int,suspended:int}
     */
    public function runDunning(): array
    {
        $stats = ['overdue' => 0, 'reminded' => 0, 'suspended' => 0];

        // 1. sent + échue → overdue.
        $newlyOverdue = Invoice::where('status', 'sent')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get();
        foreach ($newlyOverdue as $invoice) {
            $invoice->update(['status' => 'overdue']);
            AuditLogger::log('invoice.overdue', $invoice);
            $stats['overdue']++;
        }

        // 2. Relances et suspension sur toutes les factures en retard.
        $overdue = Invoice::with(['subscription.plan', 'subscription.organization', 'subscription.hotel'])
            ->where('status', 'overdue')
            ->whereNotNull('due_at')
            ->get();

        foreach ($overdue as $invoice) {
            $daysLate = (int) $invoice->due_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
            $meta     = $invoice->metadata ?? [];
            $sent     = $meta['dunning_sent'] ?? [];

            foreach (self::DUNNING_REMINDER_DAYS as $threshold) {
                if ($daysLate >= $threshold && !in_array($threshold, $sent, true)) {
                    $this->sendOverdueReminder($invoice, $daysLate);
                    $sent[] = $threshold;
                    $stats['reminded']++;
                }
            }

            if ($daysLate >= self::DUNNING_SUSPEND_DAYS && empty($meta['dunning_suspended'])) {
                $this->suspendForNonPayment($invoice);
                $meta['dunning_suspended'] = true;
                $stats['suspended']++;
            }

            $meta['dunning_sent'] = $sent;
            $invoice->update(['metadata' => $meta]);
        }

        return $stats;
    }

    private function sendOverdueReminder(Invoice $invoice, int $daysLate): void
    {
        $sub = $invoice->subscription;
        $org = $sub?->organization;
        $to  = $org?->contact_email
            ?? $invoice->hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;
        $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;

        SystemMailer::send('invoice_overdue', $to, [
            'name'            => $org?->name ?? $invoice->hotel?->name ?? 'Client Qayed',
            'invoice_number'  => $invoice->invoice_number,
            'days_late'       => (string) $daysLate,
            'plan_name'       => $sub?->plan?->name ?? '—',
            'credentials_box' => SystemMailer::amountBox(Money::tnd($invoice->total_amount, $invoice->currency), $invoice->invoice_number, $locale),
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('pay_invoice', $locale)),
        ], $locale);

        AuditLogger::log('invoice.reminder_sent', $invoice, newValues: ['days_late' => $daysLate]);
    }

    private function suspendForNonPayment(Invoice $invoice): void
    {
        $sub = $invoice->subscription;
        if (!$sub || in_array($sub->status, ['suspended', 'cancelled'], true)) {
            return;
        }

        $previous = $sub->status;
        $sub->update([
            'status'           => 'suspended',
            'suspended_at'     => now(),
            'suspended_reason' => "Facture {$invoice->invoice_number} impayée depuis plus de ".self::DUNNING_SUSPEND_DAYS.' jours.',
        ]);
        $this->recordTransition($sub, 'suspended_nonpayment', $previous, 'suspended');

        $org = $sub->organization;
        $to  = $org?->contact_email
            ?? $sub->hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;
        $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;

        // Motif localise pour l'email (suspended_reason reste en francais pour l'admin).
        $days   = self::DUNNING_SUSPEND_DAYS;
        $reason = match ($locale) {
            'en' => "Invoice {$invoice->invoice_number} unpaid for more than {$days} days. Service will be restored upon payment.",
            'ar' => "الفاتورة {$invoice->invoice_number} غير مسددة منذ أكثر من {$days} يومًا. ستُستعاد الخدمة فور استلام الدفعة.",
            default => "Facture {$invoice->invoice_number} impayée depuis plus de {$days} jours. Le service sera rétabli dès réception du paiement.",
        };
        SystemMailer::send('account_suspended', $to, [
            'name'   => $org?->name ?? $sub->hotel?->name ?? 'Client Qayed',
            'reason' => $reason,
        ], $locale);
    }

    // ─── Paiement confirmé (tous canaux) ─────────────────────────────────────

    /**
     * À appeler chaque fois qu'une facture vient d'être payée, quel que soit
     * le canal (Flouci, virement validé, saisie admin) :
     *  1. garantit la trace dans l'historique des paiements ;
     *  2. réactive et/ou prolonge l'abonnement ;
     *  3. envoie l'email « Paiement reçu ».
     */
    public function handleInvoicePaid(Invoice $invoice, ?string $recordedBy = null): void
    {
        $this->ensurePaymentRecord($invoice, $recordedBy);
        $this->applyPaymentToSubscription($invoice);
        $this->sendPaymentReceived($invoice->fresh());
    }

    /** Jamais de facture payée sans trace : complète le pending ou crée un paiement manuel. */
    public function ensurePaymentRecord(Invoice $invoice, ?string $recordedBy = null): void
    {
        if ($invoice->payments()->where('status', 'completed')->exists()) {
            return;
        }

        $pending = $invoice->payments()->where('status', 'pending')->latest('created_at')->first();
        if ($pending) {
            $pending->update(['status' => 'completed', 'completed_at' => $invoice->paid_at ?? now()]);
            AuditLogger::log('payment.recorded', $pending, newValues: ['invoice_id' => $invoice->id]);

            return;
        }

        $payment = Payment::create([
            'invoice_id'         => $invoice->id,
            'hotel_id'           => $invoice->hotel_id,
            'provider'           => $invoice->payment_method ?: 'virement',
            'declared_reference' => $invoice->payment_reference,
            'status'             => 'completed',
            'amount'             => $invoice->total_amount,
            'currency'           => $invoice->currency,
            'completed_at'       => $invoice->paid_at ?? now(),
            'provider_response'  => ['recorded_by' => $recordedBy, 'source' => 'admin_invoice_update'],
        ]);
        AuditLogger::log('payment.recorded', $payment, newValues: ['invoice_id' => $invoice->id]);
    }

    /**
     * Réactivation automatique dès paiement confirmé : une facture de
     * renouvellement prolonge l'abonnement jusqu'à la fin de sa période ;
     * un abonnement suspendu/expiré repart. La date repart de l'échéance
     * si elle est future (payer en avance ne fait pas perdre de jours).
     */
    private function applyPaymentToSubscription(Invoice $invoice): void
    {
        $sub = $invoice->subscription;
        if (!$sub || $sub->status === 'cancelled') {
            return;
        }

        $previous = $sub->status;
        $updates  = [];

        $periodEnd = isset($invoice->metadata['renewal_period_end'])
            ? \Illuminate\Support\Carbon::parse($invoice->metadata['renewal_period_end'])
            : null;

        if ($periodEnd && $periodEnd->isAfter($sub->expires_at)) {
            $updates['expires_at'] = $periodEnd;
        } elseif (!$periodEnd && in_array($previous, ['expired', 'suspended', 'trial_expired'], true)) {
            // Paiement hors renouvellement automatique (facture manuelle) sur un
            // abonnement retombé : nouvelle période complète à partir d'aujourd'hui.
            $base = $sub->expires_at?->isFuture() ? $sub->expires_at->copy() : now();
            $updates['expires_at'] = $sub->billing_cycle === 'yearly' ? $base->addYear() : $base->addMonth();
        }

        if (in_array($previous, ['expired', 'suspended', 'trial_expired', 'trial'], true)) {
            $updates['status']           = 'active';
            $updates['suspended_at']     = null;
            $updates['suspended_reason'] = null;
        }

        if ($updates === []) {
            return;
        }

        $sub->update($updates);
        $this->recordTransition($sub, 'payment_confirmed', $previous, $sub->status);
    }

    private function sendPaymentReceived(Invoice $invoice): void
    {
        $invoice->loadMissing(['hotel', 'subscription.organization', 'subscription.plan']);
        $sub = $invoice->subscription;
        $org = $sub?->organization ?? $invoice->hotel?->organization;

        $to = $org?->contact_email
            ?? $invoice->hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;
        $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;

        SystemMailer::send('payment_received', $to, [
            'name'            => $org?->name ?? $invoice->hotel?->name ?? 'Client Qayed',
            'plan_name'       => $sub?->plan?->name ?? '—',
            'expires_at'      => $sub?->fresh()?->expires_at?->format('d/m/Y') ?? '—',
            'credentials_box' => SystemMailer::amountBox(Money::tnd($invoice->total_amount, $invoice->currency), $invoice->invoice_number, $locale),
        ], $locale);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function recordTransition(Subscription $sub, string $eventType, string $previous, string $new): void
    {
        SubscriptionEvent::create([
            'subscription_id' => $sub->id,
            'event_type'      => $eventType,
            'previous_status' => $previous,
            'new_status'      => $new,
            'created_at'      => now(),
        ]);
        AuditLogger::log("subscription.{$eventType}", $sub, oldValues: ['status' => $previous], newValues: ['status' => $new, 'expires_at' => (string) $sub->expires_at]);

        if ($sub->hotel_id) {
            Cache::forget("hotel_subscription_active:{$sub->hotel_id}");
        }
        if ($sub->organization_id) {
            Cache::forget("org_subscription_active:{$sub->organization_id}");
        }
    }
}
