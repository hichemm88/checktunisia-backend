<?php

namespace App\Services\Subscription;

use App\Models\CheckIn;
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
 * Comptage : fiches créées dans le mois, statut ≠ cancelled (une fiche
 * annulée n'est pas une déclaration), soft-deleted exclues.
 */
class CheckinQuota
{
    /** Quota effectif de l'organisation (null = illimité). */
    public static function quota(Organization $org): ?int
    {
        return PlanEntitlements::limit($org, 'checkins_per_month');
    }

    /** Check-ins comptabilisés sur le mois calendaire de $month (défaut : mois courant), toutes propriétés confondues. */
    public static function usedInMonth(Organization $org, ?Carbon $month = null): int
    {
        $month = ($month ?? now())->copy()->startOfMonth();

        return CheckIn::whereHas('hotel', fn ($q) => $q->where('organization_id', $org->id))
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $month)
            ->where('created_at', '<', $month->copy()->addMonth())
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
     * État complet du quota pour une organisation sur un mois calendaire —
     * payload commun au dashboard opérateur, à la fiche hébergeur admin et
     * à l'écran de pilotage Quotas.
     *
     * @return array{
     *   quota: int|null, used: int, remaining: int|null, percent: int|null,
     *   overage_count: int, bundle_size: int|null, bundle_count: int,
     *   unit_price: float|null, overage_amount: float|null,
     *   billable: bool, legacy: bool, unlimited: bool
     * }
     */
    public static function status(Organization $org, ?Carbon $month = null): array
    {
        $sub   = $org->activeSubscription()->with('plan')->first();
        $plan  = $sub?->plan;
        $quota = self::quota($org);
        $used  = self::usedInMonth($org, $month);

        if ($quota === null) {
            return [
                'quota' => null, 'used' => $used, 'remaining' => null, 'percent' => null,
                'overage_count' => 0, 'bundle_size' => null, 'bundle_count' => 0,
                'unit_price' => null, 'overage_amount' => null,
                'billable' => false, 'legacy' => (bool) ($sub?->is_legacy_plan), 'unlimited' => true,
            ];
        }

        $bundleSize  = (int) ($plan?->overage_bundle_size ?? 0) >= 1 ? (int) $plan->overage_bundle_size : null;
        $unitPrice   = $plan?->overage_price !== null ? (float) $plan->overage_price : null;
        $overage     = max(0, $used - $quota);
        $bundles     = $bundleSize ? self::bundleCount($used, $quota, $bundleSize) : 0;
        $billable    = self::overageBillable($sub);

        return [
            'quota'          => $quota,
            'used'           => $used,
            'remaining'      => max(0, $quota - $used),
            'percent'        => $quota > 0 ? (int) floor($used * 100 / $quota) : 100,
            'overage_count'  => $overage,
            'bundle_size'    => $bundleSize,
            'bundle_count'   => $bundles,
            'unit_price'     => $unitPrice,
            'overage_amount' => ($unitPrice !== null && $bundleSize !== null) ? round($bundles * $unitPrice, 3) : null,
            'billable'       => $billable,
            'legacy'         => (bool) ($sub?->is_legacy_plan),
            'unlimited'      => false,
        ];
    }
}
