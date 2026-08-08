<?php

namespace App\Services\Subscription;

use App\Models\CheckinUsageEvent;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Quota mensuel de check-ins (grille V2) — source de vérité unique du
 * comptage, de la résolution du quota effectif et du calcul des tranches
 * de dépassement.
 *
 * RÈGLE ABSOLUE : le quota n'est JAMAIS bloquant. La déclaration d'un
 * voyageur est une obligation légale — un établissement au-delà de son
 * plafond continue d'enregistrer ses check-ins ; le dépassement est
 * facturé a posteriori (comptes non-legacy), pas empêché. Ne jamais
 * appeler PlanEntitlements::assertWithinLimit avec `checkins_per_month`.
 *
 * Cycle de comptage : MOIS CALENDAIRE (comme `ocr_scans_per_month`).
 * Le cycle de facturation des abonnements (started_at/expires_at) ne
 * porte pas d'ancre mensuelle exploitable pour les abonnements annuels ;
 * le mois calendaire est le seul cycle commun à tous les comptes et
 * s'aligne sur les compteurs existants (dashboards, scans OCR).
 *
 * SOURCE DE VÉRITÉ DU COMPTAGE : le registre `checkin_usage_events`, en
 * ajout seul, une ligne par fiche FINALISÉE, unicité SQL sur check_in_id.
 * Voir CheckinUsageRecorder. Le comptage ne se déduit plus de l'état
 * courant des fiches : un brouillon ne consomme rien, une annulation
 * postérieure ne rembourse rien, et l'historique ne bouge plus.
 */
class CheckinQuota
{
    /** Quota effectif de l'organisation (null = illimité). */
    public static function quota(Organization $org): ?int
    {
        return PlanEntitlements::limit($org, 'checkins_per_month');
    }

    /**
     * Check-ins facturables du mois calendaire de $month (défaut : mois
     * courant), toutes propriétés confondues — lecture directe du registre.
     *
     * Les lignes annulées après coup restent comptées (la déclaration a bien
     * été rendue) ; `cancelledInMonth` les expose séparément pour que le
     * client et l'admin puissent lire le chiffre sans ambiguïté.
     */
    public static function usedInMonth(Organization $org, ?Carbon $month = null): int
    {
        return CheckinUsageEvent::where('organization_id', $org->id)
            ->where('period', ($month ?? now())->copy()->startOfMonth()->toDateString())
            ->count();
    }

    /** Part des consommations du mois dont le séjour a été annulé depuis (informatif, déjà incluse dans usedInMonth). */
    public static function cancelledInMonth(Organization $org, ?Carbon $month = null): int
    {
        return CheckinUsageEvent::where('organization_id', $org->id)
            ->where('period', ($month ?? now())->copy()->startOfMonth()->toDateString())
            ->whereNotNull('cancelled_at')
            ->count();
    }

    /** Tranches entamées au-delà du quota (0 si pas de dépassement). */
    public static function bundleCount(int $used, int $quota, int $bundleSize): int
    {
        if ($bundleSize < 1 || $used <= $quota) {
            return 0;
        }

        return (int) ceil(($used - $quota) / $bundleSize);
    }

    /**
     * Le dépassement de cet abonnement est-il facturable automatiquement ?
     * Jamais pour un compte grandfathered (il conserve ses conditions) ;
     * sinon uniquement si le plan définit un prix et une taille de tranche.
     */
    public static function overageBillable(?Subscription $sub): bool
    {
        if (!$sub || $sub->is_legacy_plan) {
            return false;
        }

        $plan = $sub->plan;

        return $plan !== null
            && $plan->overage_price !== null
            && (int) ($plan->overage_bundle_size ?? 0) >= 1;
    }

    /**
     * Montant de l'abonnement rapporté au mois, hors dépassement — base du
     * « total estimé ». Passe par PlanPricing (formule unique : base +
     * établissements supplémentaires, prix négocié prioritaire) ; un
     * abonnement annuel est ramené à sa valeur mensuelle.
     */
    public static function monthlyBase(?Subscription $sub): ?float
    {
        if (!$sub) {
            return null;
        }

        return round($sub->billing_cycle === 'yearly'
            ? PlanPricing::monthlyValue($sub)
            : (float) PlanPricing::detail($sub)['monthly_total'], 3);
    }

    /**
     * État complet du quota pour une organisation sur un mois calendaire —
     * payload commun au dashboard opérateur, à l'espace hébergeur, à la fiche
     * admin et à l'écran de pilotage Quotas.
     *
     * C'est le SEUL endroit où le montant d'un dépassement est calculé pour
     * affichage : aucune surface cliente ne recompose un prix. Le total
     * estimé (`estimated_total`) est indicatif — la facture qui fait foi est
     * émise à la clôture du mois (quota:close-month).
     *
     * @return array{
     *   period: string, quota: int|null, used: int, cancelled: int,
     *   remaining: int|null, percent: int|null, overage_count: int,
     *   bundle_size: int|null, bundle_count: int, unit_price: float|null,
     *   overage_amount: float|null, monthly_base: float|null,
     *   estimated_total: float|null, billable: bool, legacy: bool, unlimited: bool
     * }
     */
    public static function status(Organization $org, ?Carbon $month = null): array
    {
        $sub    = $org->activeSubscription()->with('plan')->first();
        $plan   = $sub?->plan;
        $quota  = self::quota($org);
        $used   = self::usedInMonth($org, $month);
        $base   = self::monthlyBase($sub);
        $period = ($month ?? now())->copy()->startOfMonth()->toDateString();

        if ($quota === null) {
            return [
                'period' => $period,
                'quota' => null, 'used' => $used, 'cancelled' => self::cancelledInMonth($org, $month),
                'remaining' => null, 'percent' => null,
                'overage_count' => 0, 'bundle_size' => null, 'bundle_count' => 0,
                'unit_price' => null, 'overage_amount' => null,
                'monthly_base' => $base, 'estimated_total' => $base,
                'billable' => false, 'legacy' => (bool) ($sub?->is_legacy_plan), 'unlimited' => true,
            ];
        }

        $bundleSize  = (int) ($plan?->overage_bundle_size ?? 0) >= 1 ? (int) $plan->overage_bundle_size : null;
        $unitPrice   = $plan?->overage_price !== null ? (float) $plan->overage_price : null;
        $overage     = max(0, $used - $quota);
        $bundles     = $bundleSize ? self::bundleCount($used, $quota, $bundleSize) : 0;
        $billable    = self::overageBillable($sub);
        $overageCost = ($unitPrice !== null && $bundleSize !== null) ? round($bundles * $unitPrice, 3) : null;

        return [
            'period'         => $period,
            'quota'          => $quota,
            'used'           => $used,
            'cancelled'      => self::cancelledInMonth($org, $month),
            'remaining'      => max(0, $quota - $used),
            'percent'        => $quota > 0 ? (int) floor($used * 100 / $quota) : 100,
            'overage_count'  => $overage,
            'bundle_size'    => $bundleSize,
            'bundle_count'   => $bundles,
            'unit_price'     => $unitPrice,
            'overage_amount' => $overageCost,
            'monthly_base'   => $base,
            // Un dépassement non facturable (compte grandfathered) n'alourdit
            // jamais le total annoncé au client.
            'estimated_total' => $base === null ? null : round($base + ($billable ? ($overageCost ?? 0) : 0), 3),
            'billable'       => $billable,
            'legacy'         => (bool) ($sub?->is_legacy_plan),
            'unlimited'      => false,
        ];
    }
}
