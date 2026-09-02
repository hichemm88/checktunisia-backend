<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Subscription\CommercialMetrics;
use App\Services\Subscription\PlanPricing;
use App\Services\Whatsapp\WhatsappCostRecorder;
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
 * - Les comptes INTERNES (billing_mode = internal) sont hors perimetre
 *   commercial : ils utilisent le produit sans l'acheter. Exclus de TOUTES
 *   les metriques ci-dessous, y compris les cohortes d'activation et de
 *   conversion, qui mesurent un entonnoir commercial.
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
        // Source UNIQUE, partagee avec /admin/dashboard : deduplication par
        // client et barème n'existent qu'a un seul endroit (CommercialMetrics).
        $metrics    = app(CommercialMetrics::class);
        $activeSubs = $metrics->activeSubscriptions();

        $payingCustomers = $activeSubs->count();
        $mrrCurrent = $metrics->mrr($activeSubs);
        $arrCurrent = $metrics->arr($activeSubs);

        // ─── Nouveau MRR du mois ──────────────────────────────────────────
        $newSubs = $activeSubs->filter(fn ($s) => $s->started_at && $s->started_at->gte($monthStart));
        $mrrNew = round($newSubs->sum(fn ($s) => PlanPricing::monthlyValue($s)), 3);

        // ─── MRR perdu (churn) du mois ────────────────────────────────────
        // Abonnements resilies (cancelled_at ce mois) ou arrives a expiration
        // (status expired, expires_at ce mois), dont le client n'a plus aucun
        // abonnement actif (sinon c'est un changement de pack, pas un depart).
        $activeOrgIds = $activeSubs->pluck('organization_id')->filter()->unique();

        $endedSubs = Subscription::with(['plan', 'organization'])
            ->commercial()
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
        $cohort = Organization::commercial()
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())->pluck('id');
        $activated = $cohort->isNotEmpty()
            ? Organization::whereIn('id', $cohort)
                ->whereHas('properties', fn ($q) => $q->whereHas('checkIns'))
                ->count()
            : 0;
        $activationRate = $cohort->isNotEmpty() ? round($activated / $cohort->count() * 100, 1) : null;

        // ─── Conversion d'essai (trial -> payant) ─────────────────────────
        // Meme logique que le dashboard : orgs ayant eu un essai et detenant
        // aujourd'hui un abonnement actif.
        $orgsWithTrial = Organization::commercial()
            ->whereHas('subscriptions', fn ($q) => $q->whereRaw("metadata->>'trial' = 'true'"))->pluck('id');
        $convertedTrials = $orgsWithTrial->isNotEmpty()
            ? Organization::whereIn('id', $orgsWithTrial)
                ->whereHas('subscriptions', fn ($q) => $q->where('status', 'active'))
                ->count()
            : 0;
        $trialConversionRate = $orgsWithTrial->isNotEmpty()
            ? round($convertedTrials / $orgsWithTrial->count() * 100, 1)
            : null;

        // ─── Couts Meta / WhatsApp du mois ────────────────────────────────
        // Source unique, partagee avec /admin/meta-costs : la grille tarifaire
        // et le choix de source (reel Meta vs estimation locale) n'existent
        // qu'a un seul endroit. En USD, comme la facture Meta et comme les
        // couts IA — le reste de ce payload est en TND, d'ou le champ
        // `currency` explicite sur ce bloc et sur lui seul.
        $metaCosts = app(WhatsappCostRecorder::class)->currentMonthTotals();

        return response()->json([
            'data' => [
                'currency' => self::CURRENCY,
                'meta_costs' => $metaCosts,
                'mrr' => [
                    'current'            => $mrrCurrent,
                    'new_this_month'     => $mrrNew,
                    'churned_this_month' => $mrrChurned,
                    'net_new_this_month' => round($mrrNew - $mrrChurned, 3),
                ],
                // Meme base d'abonnements que le MRR, autre echelle : un
                // annuel vaut le montant reellement facture, pas douze fois
                // sa mensualisation arrondie.
                'arr' => [
                    'current' => $arrCurrent,
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
