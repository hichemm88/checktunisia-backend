<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OverageCharge;
use App\Services\Billing\BillingService;
use App\Services\Subscription\CheckinQuota;
use App\Services\Subscription\PlanPricing;
use App\Services\Subscription\QuotaAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Clôture mensuelle des quotas de check-ins (grille V2), planifiée le 1er
 * du mois pour le mois écoulé :
 *
 *  1. calcule les tranches de dépassement de chaque organisation à quota
 *     fini (une ligne overage_charges par organisation et par mois) ;
 *  2. facture automatiquement les comptes NON-legacy (BillingService) —
 *     les comptes grandfathered sont calculés (pilotage commercial) mais
 *     marqués excluded_legacy, jamais facturés ;
 *  3. détecte les comptes en dépassement 2 mois consécutifs : email
 *     comparatif dédié + badge « Candidat upsell » dans l'admin.
 *
 * Idempotente : relançable sans double facturation (unicité org+mois,
 * facture liée une seule fois, alerte upsell unique par mois).
 */
class CloseMonthlyQuotas extends Command
{
    protected $signature = 'quota:close-month {--month= : Mois à clôturer (YYYY-MM), défaut : mois écoulé}';

    protected $description = 'Clôture les quotas de check-ins du mois écoulé : calcul des tranches de dépassement, facturation (comptes non-legacy) et détection des candidats upsell';

    public function handle(BillingService $billing): int
    {
        $period = $this->option('month')
            ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        $stats = ['computed' => 0, 'invoiced' => 0, 'legacy' => 0, 'upsell' => 0];

        Organization::whereHas('subscriptions', fn ($q) => $q->whereIn('status', ['active', 'trial']))
            ->chunkById(100, function ($orgs) use ($period, $billing, &$stats) {
                foreach ($orgs as $org) {
                    try {
                        $this->closeFor($org, $period, $billing, $stats);
                    } catch (\Throwable $e) {
                        Log::error("[quota] close-month failed for organization {$org->id}: ".$e->getMessage());
                        $this->error("  {$org->name} : {$e->getMessage()}");
                    }
                }
            });

        $this->info(sprintf(
            'Clôture %s : %d dépassement(s) calculé(s), %d facturé(s), %d legacy (non facturés), %d candidat(s) upsell.',
            $period->format('Y-m'), $stats['computed'], $stats['invoiced'], $stats['legacy'], $stats['upsell'],
        ));

        return self::SUCCESS;
    }

    private function closeFor(Organization $org, Carbon $period, BillingService $billing, array &$stats): void
    {
        $sub  = $org->activeSubscription()->with('plan')->first();
        $plan = $sub?->plan;
        if (!$sub) {
            return;
        }

        $quota = CheckinQuota::quota($org);
        if ($quota === null || $quota < 1) {
            return; // compte illimité — jamais de dépassement ni d'alerte
        }

        $used = CheckinQuota::usedInMonth($org, $period);
        if ($used <= $quota) {
            return;
        }

        // Paramètres de tranche du plan ; repli sur la règle standard de la
        // grille (+10 TND / tranche de 50) pour que le pilotage admin ait
        // toujours un montant estimé, même sur un plan mal configuré.
        $bundleSize = (int) ($plan?->overage_bundle_size ?? 0) >= 1 ? (int) $plan->overage_bundle_size : 50;
        $unitPrice  = $plan?->overage_price !== null ? (float) $plan->overage_price : 10.0;
        $bundles    = CheckinQuota::bundleCount($used, $quota, $bundleSize);
        $billable   = CheckinQuota::overageBillable($sub);

        // Idempotence : un dépassement déjà facturé (ou abandonné par l'admin)
        // n'est jamais recalculé ni re-statué par une relance de la commande.
        $charge = OverageCharge::firstOrNew(['organization_id' => $org->id, 'period' => $period->toDateString()]);
        if (!$charge->exists || (!$charge->invoice_id && $charge->status !== OverageCharge::STATUS_WAIVED)) {
            $charge->fill([
                'subscription_id' => $sub->id,
                'checkins_count'  => $used,
                'quota'           => $quota,
                'overage_count'   => $used - $quota,
                'bundle_size'     => $bundleSize,
                'bundle_count'    => $bundles,
                'unit_price'      => $unitPrice,
                'amount'          => round($bundles * $unitPrice, 3),
                'status'          => $billable ? OverageCharge::STATUS_PENDING : OverageCharge::STATUS_EXCLUDED_LEGACY,
            ])->save();
        }
        $stats['computed']++;

        if ($billable && !$charge->invoice_id) {
            $billing->generateOverageInvoice($sub, $charge);
            $stats['invoiced']++;
        } elseif (!$billable) {
            $stats['legacy']++;
        }

        // ── Upsell : 2 mois consécutifs en dépassement ───────────────────────
        $previous = OverageCharge::where('organization_id', $org->id)
            ->where('period', $period->copy()->subMonthNoOverflow()->toDateString())
            ->where('overage_count', '>', 0)
            ->exists();
        if (!$previous) {
            return;
        }

        $suggested = $this->suggestedPlan($plan?->slug);
        if (!$suggested) {
            return;
        }

        // Chiffres réels du compte : base actuelle + dépassements des 2 mois
        // vs prix mensuel du plan supérieur.
        $twoMonthsOverage = (float) OverageCharge::where('organization_id', $org->id)
            ->whereIn('period', [$period->toDateString(), $period->copy()->subMonthNoOverflow()->toDateString()])
            ->sum('amount');
        $currentMonthly = $sub->billing_cycle === 'yearly'
            ? PlanPricing::monthlyValue($sub)
            : PlanPricing::detail($sub)['monthly_total'];

        QuotaAlertService::sendUpsellSuggestion($org, [
            'used'           => $used,
            'quota'          => $quota,
            'suggested_plan' => $suggested->marketing['display_name']['fr'] ?? $suggested->name,
            'current'        => [
                'label'  => sprintf('%s + dépassements (2 derniers mois)', $plan?->name ?? 'Plan actuel'),
                'amount' => \App\Support\Money::tnd(round($currentMonthly * 2 + $twoMonthsOverage, 3)),
            ],
            'suggested'      => [
                'label'  => sprintf('%s (2 mois)', $suggested->name),
                'amount' => \App\Support\Money::tnd(round((float) $suggested->price_monthly * 2, 3)),
            ],
        ], $period);
        $stats['upsell']++;
    }

    /** Plan public immédiatement supérieur dans la grille V2. */
    private function suggestedPlan(?string $currentSlug): ?\App\Models\SubscriptionPlan
    {
        $target = match ($currentSlug) {
            'essentiel' => 'pro',
            default     => 'hotel',
        };

        return \App\Models\SubscriptionPlan::where('slug', $target)
            ->where('is_active', true)
            ->where('is_public', true)
            ->first();
    }
}
