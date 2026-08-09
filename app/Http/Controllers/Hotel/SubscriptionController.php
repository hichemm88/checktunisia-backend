<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPlanChange;
use App\Services\Subscription\PlanChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the current subscription for the authenticated user's organisation.
 *
 * Route is outside the 'tenant' middleware group — subscription is org-level
 * and must be readable even before a first property has been created.
 */
class SubscriptionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            $sub = $org->activeSubscription()->with('plan')->first();
        } else {
            // Legacy fallback: user has no org yet — try hotel pivot
            $hotel = $user->hotel();
            $sub   = $hotel
                ? Subscription::where('hotel_id', $hotel->id)
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest('started_at')
                    ->first()
                : null;
        }

        if (!$sub) {
            return response()->json(['data' => ['status' => 'none']]);
        }

        return response()->json([
            'data' => [
                'id'             => $sub->id,
                'plan'           => $sub->plan,
                'status'         => $sub->status,
                'billing_cycle'  => $sub->billing_cycle,
                'started_at'     => $sub->started_at,
                'expires_at'     => $sub->expires_at,
                'auto_renew'     => $sub->auto_renew,
                'days_remaining' => $sub->days_remaining,
                // Fonctionnalités effectives (pack + overrides négociés) avec
                // usage — même payload pour web et mobile.
                'entitlements'   => $org ? \App\Services\Subscription\PlanEntitlements::summary($org) : null,
                // Détail du prix : base + suppléments par établissement (ou
                // prix négocié). Même formule sur toutes les surfaces.
                'pricing'        => \App\Services\Subscription\PlanPricing::detail($sub),
                // Quota mensuel de check-ins (grille V2) + dépassement en cours.
                'quota'          => $org ? \App\Services\Subscription\CheckinQuota::status($org) : null,
                // Grandfathering : le compte conserve les conditions de
                // l'ancienne grille (affichage + demande d'upgrade).
                'is_legacy_plan' => (bool) $sub->is_legacy_plan,
                // Conditions particulières que le client perdrait en changeant
                // de plan — affichées avant toute confirmation.
                'historic_conditions' => app(PlanChangeService::class)->historicConditions($sub),
                // Résiliation programmée : le service court jusqu'à expires_at.
                'cancellation'   => [
                    'requested_at' => $sub->cancellation_requested_at,
                    'scheduled'    => $sub->isCancellationScheduled(),
                    'ends_at'      => $sub->expires_at,
                ],
                // Changement de plan en cours (attente de paiement ou programmé).
                'pending_change' => $this->pendingChangePayload($sub),
                // Prochaine échéance : quand, combien, et si une facture
                // partira toute seule. Le client ne doit jamais découvrir
                // le montant sur la facture.
                'next_renewal'   => $this->nextRenewalPayload($sub),
                // La période en cours reste-t-elle à régler ? C'est le
                // backend qui tranche : le front ne rejoue pas la liste des
                // statuts concernés, elle dériverait.
                // Un compte interne n'a jamais rien à régler.
                'awaiting_payment' => $sub->isCommercial()
                    && app(PlanChangeService::class)->awaitsPayment($sub),
                // Compte interne : l'écran masque tout ce qui relève du
                // commerce (prix à payer, factures à régler, upgrade,
                // dépassement facturable) et garde l'opérationnel.
                'billing_mode'   => $org?->billing_mode ?? \App\Models\Organization::BILLING_COMMERCIAL,
                'is_internal'    => $sub->isInternal(),
            ],
        ]);
    }

    /**
     * Ce qui attend le client à l'échéance.
     *
     * `auto_invoiced` dit la vérité sur notre capacité réelle : Qayed émet
     * la facture et prévient, le règlement reste une action du client
     * (Flouci ne permet aucun prélèvement récurrent). Le front s'appuie
     * dessus pour ne jamais parler de « prélèvement automatique ».
     */
    private function nextRenewalPayload(Subscription $sub): ?array
    {
        // Un compte interne n'a pas d'échéance commerciale : ni date de
        // renouvellement, ni montant. Renvoyer null plutôt qu'un montant à
        // zéro évite de faire croire à une facture qui n'existera jamais.
        if ($sub->isInternal()) {
            return null;
        }

        $scheduled = SubscriptionPlanChange::where('subscription_id', $sub->id)
            ->where('status', SubscriptionPlanChange::STATUS_SCHEDULED)
            ->latest('created_at')
            ->first();

        // Un downgrade programmé change le montant de la prochaine échéance :
        // c'est le nouveau plan qui sera facturé, pas l'actuel.
        $amount = $scheduled
            ? (float) $scheduled->next_renewal_amount
            : \App\Services\Subscription\PlanPricing::cycleAmount($sub);

        return [
            'due_at'        => $sub->expires_at,
            'amount'        => round($amount, 3),
            'billing_cycle' => $sub->billing_cycle,
            'plan_name'     => $scheduled?->toPlan?->name ?? $sub->plan?->name,
            // Une facture partira-t-elle seule à l'échéance ?
            'auto_invoiced' => (bool) $sub->auto_renew && $sub->status === 'active',
            'invoice_lead_days' => \App\Services\Billing\BillingService::RENEWAL_WINDOW_DAYS,
        ];
    }

    /** Le changement en cours, tel que le client doit le voir (null s'il n'y en a pas). */
    private function pendingChangePayload(Subscription $sub): ?array
    {
        $change = SubscriptionPlanChange::with(['toPlan', 'fromPlan', 'invoice'])
            ->where('subscription_id', $sub->id)
            ->whereIn('status', SubscriptionPlanChange::IN_FLIGHT)
            ->latest('created_at')
            ->first();

        if (!$change) {
            return null;
        }

        return [
            'id'                  => $change->id,
            'kind'                => $change->kind,
            'status'              => $change->status,
            'to_plan'             => $change->toPlan?->only(['id', 'slug', 'name', 'price_monthly']),
            'from_plan'           => $change->fromPlan?->only(['id', 'slug', 'name', 'price_monthly']),
            'effective_at'        => $change->effective_at,
            'amount_due'          => (float) $change->amount_due,
            'credit_applied'      => (float) $change->credit_applied,
            'next_renewal_amount' => (float) $change->next_renewal_amount,
            'invoice'             => $change->invoice ? [
                'id'             => $change->invoice->id,
                'invoice_number' => $change->invoice->invoice_number,
                'total_amount'   => $change->invoice->total_amount,
                'status'         => $change->invoice->status,
            ] : null,
        ];
    }

    // ─── Self-service : choisir, simuler, changer ────────────────────────────

    /**
     * Les plans souscriptibles, chacun accompagné de la SIMULATION complète
     * du changement pour CE client (montant dû, crédit, date d'effet, effet
     * sur le quota, conditions historiques perdues).
     *
     * Le client compare des offres déjà chiffrées pour sa situation — le
     * front n'a aucun calcul à faire, et ne pourrait pas en faire un juste.
     */
    public function plans(Request $request): JsonResponse
    {
        $sub = $this->resolveSubscription($request);
        if (!$sub) {
            return response()->json(['data' => ['current' => null, 'plans' => []]]);
        }

        $service = app(PlanChangeService::class);

        $plans = \App\Models\SubscriptionPlan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (\App\Models\SubscriptionPlan $plan) use ($sub, $service) {
                $isCurrent = $plan->id === $sub->plan_id;

                return [
                    'id'                  => $plan->id,
                    'slug'                => $plan->slug,
                    'name'                => $plan->name,
                    'marketing'           => $plan->marketing,
                    'price_monthly'       => $plan->price_monthly,
                    'features'            => $plan->features,
                    'overage_price'       => $plan->overage_price,
                    'overage_bundle_size' => $plan->overage_bundle_size,
                    'is_current'          => $isCurrent,
                    // Pas de simulation pour le plan déjà souscrit.
                    'change'              => $isCurrent ? null : $service->preview($sub, $plan),
                ];
            });

        return response()->json(['data' => [
            'current' => [
                'plan_id'       => $sub->plan_id,
                'plan_slug'     => $sub->plan?->slug,
                'billing_cycle' => $sub->billing_cycle,
                'expires_at'    => $sub->expires_at,
            ],
            'plans' => $plans,
        ]]);
    }

    /** Simulation d'un changement précis — le contenu exact de l'écran de confirmation. */
    public function previewChange(Request $request): JsonResponse
    {
        $v   = $request->validate(['plan_id' => ['required', 'integer', 'exists:subscription_plans,id']]);
        $sub = $this->resolveSubscriptionOrFail($request);

        $target = \App\Models\SubscriptionPlan::findOrFail($v['plan_id']);

        return response()->json(['data' => app(PlanChangeService::class)->preview($sub, $target)]);
    }

    /**
     * Demande de changement de plan.
     *
     * Upgrade   → facture émise, plan inchangé jusqu'au paiement confirmé.
     * Downgrade → programmé à la fin de la période déjà payée.
     *
     * `idempotency_key` est fournie par le client (une par intention) :
     * double clic, rejeu réseau ou deux onglets retombent sur la même
     * demande, donc sur la même facture.
     */
    public function changePlan(Request $request): JsonResponse
    {
        $v = $request->validate([
            'plan_id'                    => ['required', 'integer', 'exists:subscription_plans,id'],
            'idempotency_key'            => ['required', 'string', 'min:8', 'max:100'],
            'accept_conditions_change'   => ['sometimes', 'boolean'],
        ]);

        $sub    = $this->resolveSubscriptionOrFail($request);
        $target = \App\Models\SubscriptionPlan::findOrFail($v['plan_id']);

        $change = app(PlanChangeService::class)->request(
            $sub,
            $target,
            $request->user(),
            // La clé est cloisonnée par abonnement : deux tenants ne peuvent
            // pas se télescoper en envoyant la même chaîne.
            hash('sha256', $sub->id.'|'.$v['idempotency_key']),
            (bool) ($v['accept_conditions_change'] ?? false),
        );

        return response()->json(['data' => [
            'id'             => $change->id,
            'kind'           => $change->kind,
            'status'         => $change->status,
            'effective_at'   => $change->effective_at,
            'amount_due'     => (float) $change->amount_due,
            'credit_applied' => (float) $change->credit_applied,
            'applied_at'     => $change->applied_at,
            'invoice'        => $change->invoice ? [
                'id'             => $change->invoice->id,
                'invoice_number' => $change->invoice->invoice_number,
                'total_amount'   => $change->invoice->total_amount,
                'status'         => $change->invoice->status,
            ] : null,
        ]], 201);
    }

    /** Annule le changement en cours (facture d'upgrade non réglée annulée avec). */
    public function cancelChange(Request $request): JsonResponse
    {
        $sub    = $this->resolveSubscriptionOrFail($request);
        $change = app(PlanChangeService::class)->cancelPending($sub, $request->user());

        if (!$change) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'NO_PENDING_CHANGE', 'message' => 'Aucun changement de plan en cours.', 'field' => null]],
            ], 422);
        }

        return response()->json(['data' => ['id' => $change->id, 'status' => $change->status]]);
    }

    /**
     * Résiliation : arrêt du renouvellement. Le service reste ouvert jusqu'au
     * terme déjà payé, aucune donnée n'est supprimée.
     */
    public function cancelSubscription(Request $request): JsonResponse
    {
        $v   = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $sub = $this->resolveSubscriptionOrFail($request);

        $sub = app(PlanChangeService::class)->cancelRenewal($sub, $request->user(), $v['reason'] ?? null);

        return response()->json(['data' => [
            'auto_renew'                => $sub->auto_renew,
            'cancellation_requested_at' => $sub->cancellation_requested_at,
            'ends_at'                   => $sub->expires_at,
        ]]);
    }

    /** Reprise du renouvellement. */
    public function reactivateSubscription(Request $request): JsonResponse
    {
        $sub = app(PlanChangeService::class)
            ->reactivateRenewal($this->resolveSubscriptionOrFail($request), $request->user());

        return response()->json(['data' => [
            'auto_renew'                => $sub->auto_renew,
            'cancellation_requested_at' => $sub->cancellation_requested_at,
            'ends_at'                   => $sub->expires_at,
        ]]);
    }

    /**
     * Historique des mouvements d'abonnement — ce que le client doit pouvoir
     * relire pour comprendre sa facturation sans appeler personne.
     */
    public function history(Request $request): JsonResponse
    {
        $sub = $this->resolveSubscription($request);
        if (!$sub) {
            return response()->json(['data' => []]);
        }

        $events = \App\Models\SubscriptionEvent::with(['performer:id,first_name,last_name'])
            ->where('subscription_id', $sub->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $plans = \App\Models\SubscriptionPlan::whereIn(
            'id',
            $events->pluck('previous_plan_id')->merge($events->pluck('new_plan_id'))->filter()->unique(),
        )->pluck('name', 'id');

        return response()->json(['data' => $events->map(fn ($e) => [
            'id'            => $e->id,
            'event_type'    => $e->event_type,
            'created_at'    => $e->created_at,
            'previous_plan' => $e->previous_plan_id ? ($plans[$e->previous_plan_id] ?? null) : null,
            'new_plan'      => $e->new_plan_id ? ($plans[$e->new_plan_id] ?? null) : null,
            'notes'         => $e->notes,
            'by'            => $e->performer ? trim($e->performer->first_name.' '.$e->performer->last_name) : null,
        ])]);
    }

    // ─── Résolution de l'abonnement du CALLER (jamais celui d'un autre) ──────

    /**
     * L'abonnement de l'organisation de l'appelant. Aucun identifiant
     * d'abonnement n'est jamais accepté depuis la requête : c'est la session
     * qui détermine le périmètre, donc un tenant ne peut pas en désigner un
     * autre.
     *
     * Le repli sur un abonnement NON actif est délibéré : un compte expiré ou
     * suspendu doit pouvoir se reprendre en main tout seul (c'est justement
     * là que le self-service compte le plus). Un abonnement résilié
     * définitivement, lui, sort du parcours — `eligibility` le refuse.
     */
    private function resolveSubscription(Request $request): ?Subscription
    {
        $user = $request->user();

        if ($org = $user->organization) {
            return $org->activeSubscription()->with('plan', 'organization')->first()
                ?? Subscription::where('organization_id', $org->id)
                    ->with('plan', 'organization')
                    ->latest('started_at')
                    ->first();
        }

        $hotel = $user->hotel();

        return $hotel
            ? Subscription::where('hotel_id', $hotel->id)
                ->with('plan', 'organization')
                ->orderByRaw("CASE WHEN status IN ('active','trial') THEN 0 ELSE 1 END")
                ->latest('started_at')
                ->first()
            : null;
    }

    private function resolveSubscriptionOrFail(Request $request): Subscription
    {
        $sub = $this->resolveSubscription($request);

        if (!$sub) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'data'   => null,
                'errors' => [['code' => 'NO_SUBSCRIPTION', 'message' => "Aucun abonnement actif — contactez contact@qayed.tn.", 'field' => null]],
            ], 422));
        }

        return $sub;
    }

    /**
     * @deprecated Remplacé par le self-service (`POST subscription/change`).
     * Conservé pour les clients mobiles déjà déployés : notifie l'admin sans
     * rien modifier. À retirer quand le parc mobile aura basculé.
     */
    public function requestUpgrade(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;
        if (!$org) {
            return response()->json(['errors' => [['code' => 'NO_ORGANIZATION', 'message' => "Compte sans organisation — contactez contact@qayed.tn."]]], 422);
        }

        $v = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:subscription_plans,slug'],
            'message'   => ['nullable', 'string', 'max:500'],
        ]);

        $target = \App\Models\SubscriptionPlan::where('slug', $v['plan_slug'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->first();
        if (!$target) {
            return response()->json(['errors' => [['code' => 'PLAN_NOT_AVAILABLE', 'message' => "Ce plan n'est pas souscriptible."]]], 422);
        }

        $sub = $org->activeSubscription()->with('plan')->first();
        if ($sub && $sub->plan_id === $target->id && !$sub->is_legacy_plan) {
            return response()->json(['errors' => [['code' => 'ALREADY_ON_PLAN', 'message' => 'Votre compte est déjà sur ce plan.']]], 422);
        }

        if ($sub) {
            \App\Models\SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'upgrade_requested',
                'previous_status' => $sub->status,
                'new_status'      => $sub->status,
                'notes'           => "Upgrade demandé vers {$target->name}".(isset($v['message']) && $v['message'] !== '' ? ' — '.$v['message'] : ''),
                'performed_by'    => $user->id,
                'created_at'      => now(),
            ]);
        }

        \App\Services\Audit\AuditLogger::log('subscription.upgrade_requested', $sub ?? $org, newValues: [
            'organization_id' => $org->id,
            'target_plan'     => $target->slug,
        ]);

        \App\Services\Notifications\AdminNotifier::notify(
            "Demande d'upgrade : {$org->name}",
            '<h2 style="margin:0 0 12px">Demande d\'upgrade</h2><table style="font-size:14px;border-collapse:collapse">'
            .\App\Services\Notifications\AdminNotifier::row('Hébergeur', $org->name)
            .\App\Services\Notifications\AdminNotifier::row('Email', $org->contact_email)
            .\App\Services\Notifications\AdminNotifier::row('Plan actuel', $sub?->plan?->name.($sub?->is_legacy_plan ? ' (legacy)' : ''))
            .\App\Services\Notifications\AdminNotifier::row('Plan demandé', $target->name.' — '.number_format((float) $target->price_monthly, 0).' TND/mois')
            .\App\Services\Notifications\AdminNotifier::row('Message', $v['message'] ?? null)
            .'</table><p style="margin-top:16px">À appliquer depuis la fiche hébergeur (Admin &gt; Hébergeurs).</p>',
        );

        return response()->json(['data' => [
            'status'      => 'requested',
            'target_plan' => $target->only(['id', 'name', 'slug', 'price_monthly']),
        ]]);
    }

    /** Invoice history for the authenticated user's own organisation (or hotel, legacy). */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->scopedInvoices($request)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $invoices->map(fn(Invoice $inv) => [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'amount'         => $inv->amount,
                'tax_amount'     => $inv->tax_amount,
                'total_amount'   => $inv->total_amount,
                'currency'       => $inv->currency,
                'status'         => $inv->status,
                'due_at'         => $inv->due_at,
                'paid_at'        => $inv->paid_at,
                'created_at'     => $inv->created_at,
            ]),
            'meta' => ['total' => $invoices->total(), 'current_page' => $invoices->currentPage(), 'per_page' => $invoices->perPage()],
        ]);
    }

    public function downloadInvoicePdf(Request $request, string $id)
    {
        $invoice = $this->scopedInvoices($request)
            ->with(['subscription.organization', 'subscription.plan'])
            ->findOrFail($id);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'org'     => $invoice->subscription?->organization,
            'plan'    => $invoice->subscription?->plan,
            'issuer'  => \App\Models\PlatformSetting::get(),
        ])->download("facture-{$invoice->invoice_number}.pdf");
    }

    /** Invoices belonging to the caller's own organization (or hotel, legacy) — never another tenant's. */
    private function scopedInvoices(Request $request)
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            return Invoice::whereHas('subscription', fn($q) => $q->where('organization_id', $org->id));
        }

        $hotel = $user->hotel();
        return Invoice::where('hotel_id', $hotel?->id ?? '00000000-0000-0000-0000-000000000000');
    }
}
