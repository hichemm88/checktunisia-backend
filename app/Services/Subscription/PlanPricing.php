<?php

namespace App\Services\Subscription;

use App\Models\Subscription;

/**
 * Formule de prix UNIQUE, basée sur la config en base (Admin > Abonnements) :
 *
 *   prixMensuel = base + max(0, nbÉtablissements - inclus) × prixParSupplément
 *
 * - `included_properties` / `extra_property_price` vivent sur le pack ;
 *   extra_property_price null = pas d'extension possible (le pack est
 *   plafonné à included_properties par PlanEntitlements — le Multi-sites,
 *   lui, n'a PAS de plafond : seul le prix évolue).
 * - `custom_price` (prix négocié par client) prime sur tout le calcul —
 *   les abonnés existants conservent leur prix tant que l'admin n'y touche
 *   pas.
 * - Annuel = 11 × mensuel (1 mois offert), suppléments compris, sauf
 *   price_yearly explicite sur le pack (qui ne couvre que la base).
 *
 * Utilisée partout où un prix est calculé : facture de renouvellement,
 * MRR (dashboard + fiche hébergeur), facture manuelle, affichage hébergeur
 * (web et mobile passent par les mêmes endpoints).
 */
class PlanPricing
{
    /**
     * Détail du prix pour un abonnement.
     *
     * @return array{
     *   base: float, included_properties: int, property_count: int,
     *   extra_count: int, extra_property_price: float|null, extra_total: float,
     *   monthly_total: float, cycle_total: float, negotiated: bool
     * }
     */
    public static function detail(Subscription $sub, ?int $propertyCount = null): array
    {
        $plan = $sub->plan;
        $propertyCount ??= $sub->organization
            ? $sub->organization->properties()->count()
            : 1; // abonnement legacy au niveau établissement

        $included = max(1, (int) ($plan?->included_properties ?? 1));
        $extraPrice = $plan?->extra_property_price !== null ? (float) $plan->extra_property_price : null;
        $extraCount = $extraPrice !== null ? max(0, $propertyCount - $included) : 0;
        $extraTotal = $extraCount * ($extraPrice ?? 0.0);

        $baseMonthly  = (float) ($plan?->price_monthly ?? 0);
        $monthlyTotal = $baseMonthly + $extraTotal;

        // Montant pour le cycle facturé. custom_price est un montant PAR CYCLE
        // (comportement historique) et remplace tout le calcul.
        if ($sub->custom_price !== null) {
            $cycleTotal = (float) $sub->custom_price;
        } elseif ($sub->billing_cycle === 'yearly') {
            $cycleTotal = (float) ($plan?->effective_price_yearly ?? $baseMonthly * 11) + $extraTotal * 11;
        } else {
            $cycleTotal = $monthlyTotal;
        }

        return [
            'base'                 => round($baseMonthly, 3),
            'included_properties'  => $included,
            'property_count'       => $propertyCount,
            'extra_count'          => $extraCount,
            'extra_property_price' => $extraPrice,
            'extra_total'          => round($extraTotal, 3),
            'monthly_total'        => round($monthlyTotal, 3),
            'cycle_total'          => round($cycleTotal, 3),
            'negotiated'           => $sub->custom_price !== null,
        ];
    }

    /** Montant à facturer pour le cycle courant (custom_price prioritaire). */
    public static function cycleAmount(Subscription $sub): float
    {
        return self::detail($sub)['cycle_total'];
    }

    /** Valeur mensuelle normalisée pour le MRR (annuel / 12, négocié prioritaire). */
    public static function monthlyValue(Subscription $sub, ?int $propertyCount = null): float
    {
        $d = self::detail($sub, $propertyCount);
        if ($d['negotiated']) {
            return round($sub->billing_cycle === 'yearly' ? $d['cycle_total'] / 12 : $d['cycle_total'], 3);
        }

        return $sub->billing_cycle === 'yearly'
            ? round($d['cycle_total'] / 12, 3)
            : $d['monthly_total'];
    }
}
