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
use Illuminate\Support\Facades\DB;
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
            ->commercial() // un compte interne n'achète rien : rien à facturer
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
        // Garde de fond : quel que soit l'appelant (commande, admin, code
        // futur), un compte interne ne produit jamais de facture. Le filtre
        // amont ne suffit pas — c'est ici que la règle doit tenir.
        if ($sub->isInternal()) {
            return null;
        }

        $periodStart = $sub->expires_at->copy();
        $periodEnd   = $sub->billing_cycle === 'yearly'
            ? $periodStart->copy()->addYear()
            : $periodStart->copy()->addMonth();

        // Déjà facturé pour cette période, ou renouvellement déjà en attente ?
        // On ne double pas.
        //
        // Le garde ne regarde QUE les factures de renouvellement : une
        // facture de changement de plan ou de dépassement encore ouverte ne
        // doit pas priver le client de son renouvellement. Il expirerait
        // pour avoir hésité sur un upgrade, ou pour ne pas avoir encore
        // réglé un dépassement.
        $openRenewal = Invoice::where('subscription_id', $sub->id)
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->where('metadata->renewal', true)
            ->exists();
        $samePeriod = Invoice::where('subscription_id', $sub->id)
            ->where('metadata->renewal_period_start', $periodStart->toIso8601String())
            ->exists();
        if ($openRenewal || $samePeriod) {
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
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), SystemMailer::label('view_invoice', $locale)),
        ], $locale);

        return $invoice;
    }

    // ─── Dépassements de quota check-ins (grille V2) ─────────────────────────

    /**
     * Facture un dépassement de quota clôturé (quota:close-month). Comptes
     * NON-legacy uniquement — un compte grandfathered n'est jamais facturé
     * automatiquement (CheckinQuota::overageBillable, vérifié en amont).
     * Idempotent : rien si le dépassement est déjà rattaché à une facture.
     */
    public function generateOverageInvoice(Subscription $sub, \App\Models\OverageCharge $charge): ?Invoice
    {
        // Un compte interne consomme sans acheter : son dépassement reste
        // visible en pilotage, il ne devient jamais une créance.
        if ($charge->invoice_id || $charge->amount <= 0 || $sub->isInternal()) {
            return null;
        }

        $settings = PlatformSetting::get();
        $amount   = (float) $charge->amount;
        $tax      = round($amount * ((float) $settings->tax_rate) / 100, 3) + (float) $settings->timbre_fiscal;
        $monthFr  = $charge->period->locale('fr')->isoFormat('MMMM YYYY');

        $invoice = Invoice::create([
            'subscription_id' => $sub->id,
            'hotel_id'        => null,
            'invoice_number'  => $this->nextInvoiceNumber(),
            'amount'          => $amount,
            'tax_amount'      => $tax,
            'total_amount'    => $amount + $tax,
            'currency'        => 'TND',
            'status'          => 'sent',
            'due_at'          => now()->addDays(7),
            'notes'           => self::overageInvoiceNotes($charge, $monthFr),
            'metadata'        => [
                'overage'        => true,
                'overage_period' => $charge->period->toDateString(),
                'checkins_count' => $charge->checkins_count,
                'quota'          => $charge->quota,
                'overage_count'  => $charge->overage_count,
                'bundle_size'    => $charge->bundle_size,
                'bundle_count'   => $charge->bundle_count,
                'unit_price'     => (string) $charge->unit_price,
                'tax_rate'       => (string) $settings->tax_rate,
                'timbre_fiscal'  => (string) $settings->timbre_fiscal,
            ],
        ]);

        $charge->update(['invoice_id' => $invoice->id, 'status' => \App\Models\OverageCharge::STATUS_INVOICED]);

        $this->recordOverageEvent($sub, $charge, $invoice);

        AuditLogger::log('invoice.overage_generated', $invoice, newValues: [
            'invoice_number' => $invoice->invoice_number,
            'total_amount'   => (string) $invoice->total_amount,
            'overage_period' => $charge->period->toDateString(),
        ]);

        $org    = $sub->organization;
        $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;
        SystemMailer::send('invoice_available', $org?->contact_email, [
            'name'            => $org?->name ?? $sub->hotel?->name ?? 'Client Qayed',
            'plan_name'       => $sub->plan?->name ?? '—',
            'invoice_number'  => $invoice->invoice_number,
            'credentials_box' => SystemMailer::amountBox(Money::tnd($invoice->total_amount, $invoice->currency), $invoice->invoice_number, $locale),
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), SystemMailer::label('view_invoice', $locale)),
        ], $locale);

        return $invoice;
    }

    /**
     * Libellé d'une facture de dépassement — le client doit pouvoir refaire
     * le calcul de tête, sans consulter les CGV.
     *
     * Tranche de 1 (grille V3) : « 12 check-ins supplémentaires × 0,4 TND ».
     * Tranche > 1 : « 2 tranche(s) de 50 × 10 TND », la formulation reste
     * juste si la facturation par lot revient un jour.
     */
    public static function overageInvoiceNotes(\App\Models\OverageCharge $charge, string $monthFr): string
    {
        $price = rtrim(rtrim(number_format((float) $charge->unit_price, 3, ',', ' '), '0'), ',');

        $detail = (int) $charge->bundle_size === 1
            ? sprintf('%d check-in(s) supplémentaire(s) × %s TND', $charge->bundle_count, $price)
            : sprintf('%d tranche(s) de %d × %s TND', $charge->bundle_count, $charge->bundle_size, $price);

        return sprintf(
            'Dépassement de quota check-ins — %s : %d check-ins déclarés pour un quota de %d inclus, soit %s.',
            $monthFr, $charge->checkins_count, $charge->quota, $detail,
        );
    }

    /**
     * Trace la facturation d'un dépassement dans l'historique de
     * l'abonnement : l'admin doit pouvoir expliquer un montant sans lire les
     * logs. Le statut ne change pas (un dépassement ne suspend rien).
     */
    private function recordOverageEvent(Subscription $sub, \App\Models\OverageCharge $charge, Invoice $invoice): void
    {
        SubscriptionEvent::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'overage_invoiced',
            'previous_status' => $sub->status,
            'new_status'      => $sub->status,
            'notes'           => sprintf(
                '%s — %d/%d check-ins, %d facturé(s) : %s TND (facture %s).',
                $charge->period->format('m/Y'), $charge->checkins_count, $charge->quota,
                $charge->overage_count, number_format((float) $charge->amount, 3, '.', ''),
                $invoice->invoice_number,
            ),
            'created_at'      => now(),
        ]);
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

        // 1. sent + échue → overdue. Les factures d'un compte interne sont
        // laissées telles quelles : aucune créance ne court contre nous-mêmes.
        $newlyOverdue = Invoice::with('subscription.organization')
            ->where('status', 'sent')
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->get()
            ->reject(fn (Invoice $i) => $i->subscription?->isInternal());
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
            // Un compte interne ne doit ni être relancé ni être suspendu :
            // il n'y a pas de créance. Ses factures historiques restent en
            // base, on ne les touche simplement plus.
            if ($invoice->subscription?->isInternal()) {
                continue;
            }

            // Une facture d'achat de plan abandonnée ne se relance pas et ne
            // suspend personne : le client n'a jamais eu ce qu'elle vend, et
            // son abonnement courant reste dû par ailleurs. On referme
            // proprement la demande pour qu'il puisse en relancer une autre.
            if (!empty($invoice->metadata['plan_change'])) {
                $this->abandonPlanChange($invoice);
                continue;
            }

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

    /**
     * Referme une demande d'achat de plan restée impayée : la facture est
     * annulée, le changement passe en `failed`.
     *
     * C'est ce qui libère le client — l'index unique n'autorise qu'un seul
     * changement en vol, un abandon non refermé le bloquerait indéfiniment.
     */
    private function abandonPlanChange(Invoice $invoice): void
    {
        $invoice->update(['status' => 'void']);

        $changeId = $invoice->metadata['plan_change_id'] ?? null;
        if ($changeId) {
            \App\Models\SubscriptionPlanChange::whereKey($changeId)
                ->where('status', \App\Models\SubscriptionPlanChange::STATUS_PENDING_PAYMENT)
                ->update(['status' => \App\Models\SubscriptionPlanChange::STATUS_FAILED]);
        }

        AuditLogger::log('invoice.plan_change_abandoned', $invoice, newValues: [
            'plan_change_id' => $changeId,
        ]);
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
            'cta_button'      => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), SystemMailer::label('pay_invoice', $locale)),
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
     *  2. applique un changement de plan si la facture en portait un ;
     *  3. réactive et/ou prolonge l'abonnement ;
     *  4. envoie l'email « Paiement reçu ».
     *
     * Point de convergence UNIQUE de tous les canaux de paiement : c'est ce
     * qui garantit qu'un upgrade s'active de la même façon qu'il ait été
     * réglé par Flouci, par virement validé ou saisi par l'admin — et qu'il
     * ne s'active JAMAIS sans paiement confirmé.
     */
    public function handleInvoicePaid(Invoice $invoice, ?string $recordedBy = null): void
    {
        // Un seul appelant franchit ce portillon, et il est tenu par la BASE.
        //
        // Deux vérifications de paiement simultanées (double clic au retour de
        // Flouci, rejeu réseau, deux onglets) déroulaient chacune la suite :
        // deux courriels « paiement reçu », deux lignes d'historique. Un
        // limiteur de débit ne ferme pas cette fenêtre — il la rétrécit — et
        // ne couvre pas les autres canaux qui convergent ici (virement validé,
        // saisie admin).
        //
        // L'UPDATE conditionnel est atomique : la seconde requête attend le
        // verrou de ligne, puis PostgreSQL réévalue son WHERE sur la ligne
        // committée et n'affecte plus rien. Elle repart sans effet de bord.
        // Placé DANS la transaction avec les effets : si l'un échoue, la
        // marque disparaît avec lui et le paiement reste rejouable.
        $settled = DB::transaction(function () use ($invoice, $recordedBy) {
            $claimed = DB::table('invoices')
                ->where('id', $invoice->id)
                ->whereRaw("COALESCE(metadata->>'payment_settled_at', '') = ''")
                ->update([
                    'metadata' => DB::raw(
                        "jsonb_set(COALESCE(metadata, '{}'::jsonb), '{payment_settled_at}', to_jsonb(now()::text), true)"
                    ),
                ]);

            if ($claimed === 0) {
                return false; // déjà réglée : rien à refaire, rien à renvoyer
            }

            $this->ensurePaymentRecord($invoice, $recordedBy);

            // Avant la prolongation : l'upgrade ouvre lui-même une période
            // complète, il ne faut pas que les deux se cumulent.
            app(\App\Services\Subscription\PlanChangeService::class)->applyPaidUpgrade($invoice);

            $this->applyPaymentToSubscription($invoice);

            return true;
        });

        // Hors transaction, et pour le seul gagnant : un envoi de courriel ne
        // doit ni être annulé par un rollback, ni empêcher l'encaissement s'il
        // échoue.
        if ($settled) {
            $this->sendPaymentReceived($invoice->fresh());
        }
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
        // Une facture de changement de plan a déjà ouvert sa propre période
        // complète (PlanChangeService::applyChange). Prolonger ici en plus
        // offrirait un second mois pour un seul paiement.
        if (!empty($invoice->metadata['plan_change'])) {
            return;
        }

        $sub = $invoice->subscription()->first();
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
