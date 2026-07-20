<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Subscription\PlanPricing;
use Illuminate\Http\JsonResponse;

/**
 * KPIs business de la plate-forme (Dashboard admin).
 *
 * Complete le dashboard existant (qui expose deja le MRR brut et la conversion
 * d'essai) avec les indicateurs SaaS classiques : mouvement de MRR
 * (nouveau / perdu / net), ARPU, churn logo et taux d'activation.
 *
 * Montants en TND (comme le reste de la facturation), en nombres arrondis a la
 * millieme. Les taux sont en pourcentage (une decimale) ou null quand la base
 * de calcul est vide (aucune donnee => on n'invente pas un 0 % trompeur).
 *
 * Regles de comptage :
 * - Un client = une organisation (hebergeur). Un seul abonnement compte par
 *   client (le plus recent), pour ne pas gonfler les chiffres si un vieil
 *   abonnement reste « active » a cote du courant.
 * - Les essais (trial) ne rapportent rien : exclus du MRR / ARPU / churn payant.
 * - Les abonnements legacy au niveau etablissement (organization_id null) sont
 *   comptes dans le MRR comme sur le dashboard, mais pas dans le churn ni
 *   l'activation qui raisonnent au niveau organisation.
 */
class KpiController extends Controller
{
    private const CURRENCY = 'TND';

    /** GET /admin/metrics/kpis */
    public function index(): JsonResponse
    {
        $monthStart = now()->startOfMonth();

        // ─── Base payante courante ────────────────────────────────────────
        // Abonnements actifs, dedupliques par client (org, ou etablissement en
        // legacy), au prix effectif mensualise (annuel / 12, negocie prioritaire).
        $activeSubs = Subscription::with(['plan', 'organization'])
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->get()
            ->unique(fn ($s) => $s->organization_id ?? 'hotel:' . $s->hotel_id)
            ->values();

        $payingCustomers = $activeSubs->count();
        $mrrCurrent = round($activeSubs->sum(fn ($s) => PlanPricing::monthlyValue($s)), 3);

        // ─── Nouveau MRR du mois ──────────────────────────────────────────
        $newSubs = $activeSubs->filter(fn ($s) => $s->started_at && $s->started_at->gte($monthStart));
        $mrrNew = round($newSubs->sum(fn ($s) => PlanPricing::monthlyValue($s)), 3);

        // ─── MRR perdu (churn) du mois ────────────────────────────────────
        // Abonnements resilies (cancelled_at ce mois) ou arrives a expiration
        // (status expired, expires_at ce mois), dont le client n'a plus aucun
        // abonnement actif (sinon c'est un changement de pack, pas un depart).
        $activeOrgIds = $activeSubs->pluck('organization_id')->filter()->unique();

        $endedSubs = Subscription::with(['plan', 'organization'])
            ->where(function ($q) use ($monthStart) {
                $q->where(fn ($qq) => $qq->where('status', 'cancelled')->whereBetween('cancelled_at', [$monthStart, now()]))
                  ->orWhere(fn ($qq) => $qq->where('status', 'expired')->whereBetween('expires_at', [$monthStart, now()]));
            })
            ->get()
            ->filter(fn ($s) => $s->organization_id && ! $activeOrgIds->contains($s->organization_id))
            ->unique('organization_id')
            ->values();

        $churnedCustomers = $endedSubs->count();
        $mrrChurned = round($endedSubs->sum(fn ($s) => PlanPricing::monthlyValue($s)), 3);

        // Base de depart approximee = payants actuels + partis ce mois. On ne
        // dispose pas d'instantane historique, cette approximation est la norme
        // pour un churn logo mensuel calcule a la volee.
        $baseCustomers = $payingCustomers + $churnedCustomers;
        $churnRate = $baseCustomers > 0 ? round($churnedCustomers / $baseCustomers * 100, 1) : null;

        $arpu = $payingCustomers > 0 ? round($mrrCurrent / $payingCustomers, 3) : 0.0;

        // ─── Activation : inscription -> premier check-in ─────────────────
        // Cohorte = organisations creees sur les 30 derniers jours. Activee =
        // au moins un check-in (scan) sur l'un de ses etablissements.
        $cohort = Organization::where('created_at', '>=', now()->subDays(30)->startOfDay())->pluck('id');
        $activated = $cohort->isNotEmpty()
            ? Organization::whereIn('id', $cohort)
                ->whereHas('properties', fn ($q) => $q->whereHas('checkIns'))
                ->count()
            : 0;
        $activationRate = $cohort->isNotEmpty() ? round($activated / $cohort->count() * 100, 1) : null;

        // ─── Conversion d'essai (trial -> payant) ─────────────────────────
        // Meme logique que le dashboard : orgs ayant eu un essai et detenant
        // aujourd'hui un abonnement actif.
        $orgsWithTrial = Organization::whereHas('subscriptions', fn ($q) => $q->whereRaw("metadata->>'trial' = 'true'"))->pluck('id');
        $convertedTrials = $orgsWithTrial->isNotEmpty()
            ? Organization::whereIn('id', $orgsWithTrial)
                ->whereHas('subscriptions', fn ($q) => $q->where('status', 'active'))
                ->count()
            : 0;
        $trialConversionRate = $orgsWithTrial->isNotEmpty()
            ? round($convertedTrials / $orgsWithTrial->count() * 100, 1)
            : null;

        return response()->json([
            'data' => [
                'currency' => self::CURRENCY,
                'mrr' => [
                    'current'            => $mrrCurrent,
                    'new_this_month'     => $mrrNew,
                    'churned_this_month' => $mrrChurned,
                    'net_new_this_month' => round($mrrNew - $mrrChurned, 3),
                ],
                'arpu' => [
                    'value'            => $arpu,
                    'paying_customers' => $payingCustomers,
                ],
                'churn' => [
                    'rate_pct'          => $churnRate,
                    'churned_customers' => $churnedCustomers,
                    'base_customers'    => $baseCustomers,
                    'window'            => 'current_month',
                ],
                'activation' => [
                    'rate_pct'    => $activationRate,
                    'activated'   => $activated,
                    'cohort_size' => $cohort->count(),
                    'window_days' => 30,
                ],
                'trial_conversion' => [
                    'rate_pct'  => $trialConversionRate,
                    'converted' => $convertedTrials,
                    'trials'    => $orgsWithTrial->count(),
                ],
            ],
        ]);
    }
}
