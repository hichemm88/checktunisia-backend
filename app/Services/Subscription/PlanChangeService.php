<?php

namespace App\Services\Subscription;

use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanChange;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Email\SystemMailer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Changement de plan en self-service — la règle financière, en un seul
 * endroit.
 *
 * CE QUE PERMET LE SYSTÈME DE PAIEMENT (contrainte de départ, pas un choix) :
 * Flouci facture un montant unique rattaché à une facture, vérifié au retour
 * du client sur le site. Il n'y a NI carte enregistrée, NI mandat, NI
 * prélèvement récurrent. On ne peut donc pas débiter un prorata plus tard,
 * ni ajuster une somme déjà encaissée. Toute règle qui supposerait cela
 * serait une fausse promesse.
 *
 * D'où les deux règles retenues, volontairement asymétriques :
 *
 *  UPGRADE — immédiat, mais payé d'avance.
 *    Le client paie une NOUVELLE PÉRIODE COMPLÈTE du plan supérieur, moins
 *    un CRÉDIT correspondant à ce qu'il a déjà payé et pas encore consommé
 *    sur la période en cours. Le plan ne bascule qu'au paiement confirmé —
 *    jamais avant. Ce n'est pas une proratisation du prix (fragile, et
 *    impossible à régulariser sans prélèvement récurrent) : c'est un achat
 *    net, avec une remise explicite portée par la facture.
 *
 *  DOWNGRADE — programmé, jamais rétroactif.
 *    La période déjà payée va à son terme, le nouveau plan prend effet au
 *    cycle suivant. Aucun remboursement : le client garde ce qu'il a acheté
 *    jusqu'au bout. Rien n'est retiré en cours de route.
 *
 * Conséquence directe : AUCUNE double facturation possible. Un upgrade émet
 * une facture et une seule (idempotence garantie en base) ; un downgrade
 * n'en émet aucune.
 */
class PlanChangeService
{
    /**
     * États dans lesquels la période en cours n'a PAS été payée : le client
     * doit régler sa formule (première fois ou reprise). Exposé au front via
     * `GET /hotel/subscription` pour que la liste ne vive qu'ici.
     */
    public const AWAITING_PAYMENT_STATUSES = ['trial', 'trial_expired', 'expired', 'suspended'];

    public function __construct(private readonly BillingService $billing) {}

    // ─── Lecture : ce que le client voit AVANT de confirmer ──────────────────

    /**
     * Simulation complète d'un changement vers $target. Aucun effet de bord.
     *
     * C'est la SEULE source des montants affichés : le front ne recompose
     * jamais un prix, il rend ce tableau.
     *
     * @return array{
     *   kind: string, allowed: bool, reason: string|null,
     *   effective: string, effective_at: string|null,
     *   amount_due_now: float, credit_applied: float, next_renewal_amount: float,
     *   quota_from: int|null, quota_to: int|null, usage: int,
     *   usage_exceeds_new_quota: bool,
     *   overage_unit_price_from: float|null, overage_unit_price_to: float|null,
     *   loses_historic_conditions: bool, historic_conditions: array|null
     * }
     */
    public function preview(Subscription $sub, SubscriptionPlan $target): array
    {
        $org        = $sub->organization;
        $kind       = $this->kindFor($sub, $target);
        $current    = PlanPricing::detail($sub);
        $targetCost = $this->priceFor($sub, $target);

        $quotaFrom = $org ? CheckinQuota::quota($org) : null;
        $quotaTo   = $this->quotaOf($target);
        $usage     = $org ? CheckinQuota::usedInMonth($org) : 0;

        $historic = $this->historicConditions($sub);

        // Un downgrade prend effet à la fin de la période payée ; un upgrade
        // au paiement (donc « immédiat » du point de vue du client).
        $effectiveAt = $kind === SubscriptionPlanChange::KIND_DOWNGRADE
            ? $this->periodEnd($sub)
            : null;

        [$allowed, $reason] = $this->eligibility($sub, $target);

        // Le crédit ne récompense que ce qui a réellement été payé et n'a pas
        // encore été consommé. Un essai, un compte expiré ou suspendu n'a
        // rien payé d'avance : crédit nul, pas de cadeau inventé.
        $credit   = in_array($kind, SubscriptionPlanChange::PAID_UPFRONT, true)
            ? $this->unusedCredit($sub, $current['cycle_total'], $targetCost['cycle_total'])
            : 0.0;
        $amountDue = in_array($kind, SubscriptionPlanChange::PAID_UPFRONT, true)
            ? round(max(0, $targetCost['cycle_total'] - $credit), 3)
            : 0.0;

        return [
            'kind'                      => $kind,
            'allowed'                   => $allowed,
            'reason'                    => $reason,
            'effective'                 => $kind === SubscriptionPlanChange::KIND_DOWNGRADE ? 'next_cycle' : 'on_payment',
            'effective_at'              => $effectiveAt?->toIso8601String(),
            'amount_due_now'            => $amountDue,
            'credit_applied'            => round($credit, 3),
            'current_cycle_amount'      => $current['cycle_total'],
            'next_renewal_amount'       => $targetCost['cycle_total'],
            'billing_cycle'             => $sub->billing_cycle,
            'quota_from'                => $quotaFrom,
            'quota_to'                  => $quotaTo,
            'usage'                     => $usage,
            'usage_exceeds_new_quota'   => $quotaTo !== null && $usage > $quotaTo,
            'overage_unit_price_from'   => $sub->plan?->overage_price !== null ? (float) $sub->plan->overage_price : null,
            'overage_unit_price_to'     => $target->overage_price !== null ? (float) $target->overage_price : null,
            'loses_historic_conditions' => $historic !== null,
            'historic_conditions'       => $historic,
        ];
    }

    /**
     * Le changement est-il recevable ? Séparé du calcul pour que l'UI puisse
     * afficher un plan grisé AVEC son motif, plutôt que de le masquer.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function eligibility(Subscription $sub, SubscriptionPlan $target): array
    {
        if (!$target->is_active || !$target->is_public) {
            return [false, "Ce plan n'est pas souscriptible."];
        }
        // Un compte interne n'achète pas : aucun achat, aucune souscription,
        // aucun changement payant. Son plan se règle depuis le back-office.
        if ($sub->isInternal()) {
            return [false, 'Ce compte est un compte interne : il n\'est pas soumis à la facturation.'];
        }
        if ($sub->status === 'cancelled') {
            return [false, 'Cet abonnement est résilié — contactez contact@qayed.tn pour le reprendre.'];
        }
        // Rester sur son plan n'est un « non-changement » que si la période
        // en cours est déjà payée. En essai ou sur un compte retombé, c'est
        // au contraire l'acte le plus courant : payer la formule qu'on avait
        // choisie. Le refuser obligeait le client à passer par nous.
        if ($sub->plan_id === $target->id && !$this->awaitsPayment($sub)) {
            return [false, 'Votre compte est déjà sur ce plan.'];
        }

        return [true, null];
    }

    /**
     * L'abonnement attend-il un premier règlement (ou un règlement de
     * reprise) ? Ces états n'ont RIEN payé d'avance : pas de crédit, et une
     * souscription sur le même plan y est légitime.
     */
    public function awaitsPayment(Subscription $sub): bool
    {
        return in_array($sub->status, self::AWAITING_PAYMENT_STATUSES, true);
    }

    /**
     * Upgrade ou downgrade ? Décidé sur le prix mensuel du PACK, jamais sur
     * le prix négocié du client : c'est la grille publique qui définit ce
     * qui est « supérieur », sinon un client au tarif remisé verrait ses
     * upgrades requalifiés en downgrades.
     */
    public function kindFor(Subscription $sub, SubscriptionPlan $target): string
    {
        // Un compte qui n'a pas encore payé sa période SOUSCRIT, quel que
        // soit le plan visé : parler d'« upgrade » à un essai qui règle sa
        // première facture n'aurait aucun sens pour lui.
        if ($this->awaitsPayment($sub)) {
            return SubscriptionPlanChange::KIND_SUBSCRIBE;
        }

        $from = (float) ($sub->plan?->price_monthly ?? 0);

        return (float) $target->price_monthly >= $from
            ? SubscriptionPlanChange::KIND_UPGRADE
            : SubscriptionPlanChange::KIND_DOWNGRADE;
    }

    /** Prix du plan cible pour CE client (mêmes suppléments d'établissements, sans le prix négocié). */
    private function priceFor(Subscription $sub, SubscriptionPlan $target): array
    {
        $hypothetical = $sub->replicate();
        $hypothetical->setRelation('plan', $target);
        $hypothetical->setRelation('organization', $sub->organization);
        // Un prix négocié est attaché au plan négocié : il ne suit pas un
        // changement volontaire vers un autre pack.
        $hypothetical->custom_price = null;

        $propertyCount = $sub->organization?->properties()->count() ?? 1;

        return PlanPricing::detail($hypothetical, $propertyCount);
    }

    /** Quota inclus d'un pack (null = illimité). */
    private function quotaOf(SubscriptionPlan $plan): ?int
    {
        $value = $plan->features['checkins_per_month'] ?? null;

        return ($value === null || (int) $value < 0) ? null : (int) $value;
    }

    /** Fin de la période en cours — la date jusqu'à laquelle le client a payé. */
    private function periodEnd(Subscription $sub): Carbon
    {
        return $sub->expires_at?->copy() ?? now();
    }

    /**
     * Valeur de la période déjà payée et non consommée, convertie en remise.
     * Plafonnée au prix du nouveau plan : une remise ne crée jamais d'avoir.
     */
    private function unusedCredit(Subscription $sub, float $currentCycleTotal, float $targetCycleTotal): float
    {
        if ($sub->status !== 'active' || !$sub->expires_at || $sub->expires_at->isPast()) {
            return 0.0;
        }

        $periodEnd   = $sub->expires_at->copy();
        $periodStart = $sub->billing_cycle === 'yearly'
            ? $periodEnd->copy()->subYear()
            : $periodEnd->copy()->subMonthNoOverflow();

        $totalDays = max(1, (int) round($periodStart->diffInDays($periodEnd)));
        $daysLeft  = max(0, min($totalDays, (int) ceil(now()->diffInDays($periodEnd, false))));

        return round(min($targetCycleTotal, $currentCycleTotal * $daysLeft / $totalDays), 3);
    }

    /**
     * Conditions historiques que le client perdrait en changeant de plan —
     * quota gelé, prix négocié, grandfathering. null s'il n'en a aucune.
     *
     * Ces conditions ne sont JAMAIS retirées automatiquement : elles ne
     * disparaissent que si le client demande lui-même un changement de plan
     * et confirme explicitement (voir `request`).
     */
    public function historicConditions(Subscription $sub): ?array
    {
        $overrides = (array) ($sub->metadata['feature_overrides'] ?? []);
        $frozen    = $overrides['checkins_per_month'] ?? null;

        $conditions = [];

        if ($frozen !== null) {
            $conditions['checkins_per_month'] = (int) $frozen < 0 ? null : (int) $frozen;
            $conditions['checkins_label']     = (int) $frozen < 0 ? 'illimité' : (string) (int) $frozen;
        }
        if ($sub->custom_price !== null) {
            $conditions['custom_price'] = (float) $sub->custom_price;
        }
        if ($sub->is_legacy_plan) {
            $conditions['legacy_plan'] = true;
        }

        return $conditions === [] ? null : $conditions;
    }

    // ─── Écriture : demander un changement ───────────────────────────────────

    /**
     * Enregistre la demande. Un upgrade émet une facture et laisse le plan
     * INCHANGÉ jusqu'au paiement ; un downgrade est simplement programmé.
     *
     * Idempotent par construction : `idempotency_key` est unique en base, et
     * un index partiel n'autorise qu'un changement en cours par abonnement.
     * Un double clic, un rejeu réseau ou deux onglets retombent sur la même
     * ligne — donc sur la même facture, jamais sur un second prélèvement.
     */
    public function request(
        Subscription $sub,
        SubscriptionPlan $target,
        ?User $actor,
        string $idempotencyKey,
        bool $acceptConditionsChange = false,
    ): SubscriptionPlanChange {
        $preview = $this->preview($sub, $target);

        if (!$preview['allowed']) {
            $this->fail('PLAN_CHANGE_NOT_ALLOWED', $preview['reason'] ?? "Ce changement n'est pas possible.");
        }

        // Un client historique doit dire OUI en connaissance de cause. On ne
        // dégrade jamais des conditions négociées sur un simple clic.
        if ($preview['loses_historic_conditions'] && !$acceptConditionsChange) {
            $this->fail(
                'HISTORIC_CONDITIONS_CONFIRMATION_REQUIRED',
                'Votre compte bénéficie de conditions particulières qui seront remplacées par celles du nouveau plan. Confirmez explicitement pour continuer.',
            );
        }

        return DB::transaction(function () use ($sub, $target, $actor, $idempotencyKey, $preview, $acceptConditionsChange) {
            // Rejeu exact de la même intention : on rend la ligne existante.
            $replay = SubscriptionPlanChange::where('idempotency_key', $idempotencyKey)->first();
            if ($replay) {
                return $replay;
            }

            // Verrou sur l'abonnement : deux requêtes simultanées porteuses de
            // clés différentes ne peuvent pas ouvrir deux changements.
            Subscription::whereKey($sub->id)->lockForUpdate()->first();

            $inFlight = SubscriptionPlanChange::where('subscription_id', $sub->id)
                ->whereIn('status', SubscriptionPlanChange::IN_FLIGHT)
                ->first();
            if ($inFlight) {
                $this->fail(
                    'PLAN_CHANGE_ALREADY_IN_PROGRESS',
                    $inFlight->status === SubscriptionPlanChange::STATUS_PENDING_PAYMENT
                        ? 'Un changement de plan est déjà en attente de paiement. Réglez ou annulez cette demande avant d\'en lancer une autre.'
                        : 'Un changement de plan est déjà programmé. Annulez-le avant d\'en programmer un autre.',
                );
            }

            $change = SubscriptionPlanChange::create([
                'subscription_id'            => $sub->id,
                'organization_id'            => $sub->organization_id,
                'from_plan_id'               => $sub->plan_id,
                'to_plan_id'                 => $target->id,
                'kind'                       => $preview['kind'],
                'status'                     => in_array($preview['kind'], SubscriptionPlanChange::PAID_UPFRONT, true)
                    ? SubscriptionPlanChange::STATUS_PENDING_PAYMENT
                    : SubscriptionPlanChange::STATUS_SCHEDULED,
                'effective_at'               => $preview['effective_at'] ? Carbon::parse($preview['effective_at']) : null,
                'amount_due'                 => $preview['amount_due_now'],
                'credit_applied'             => $preview['credit_applied'],
                'next_renewal_amount'        => $preview['next_renewal_amount'],
                'from_conditions'            => $preview['historic_conditions'],
                'conditions_change_accepted' => $acceptConditionsChange,
                'idempotency_key'            => $idempotencyKey,
                'requested_by'               => $actor?->id,
            ]);

            if (in_array($change->kind, SubscriptionPlanChange::PAID_UPFRONT, true)) {
                // Un achat à 0 TND (crédit couvrant tout le prix) n'a pas de
                // facture à payer : il s'applique tout de suite.
                if ((float) $change->amount_due <= 0) {
                    $this->applyChange($change, 'upgrade_applied');
                } else {
                    $change->update(['invoice_id' => $this->createUpgradeInvoice($sub, $target, $change)->id]);
                }
            }

            $this->recordEvent($sub, $change, $actor);

            return $change->fresh(['toPlan', 'fromPlan', 'invoice']);
        });
    }

    /** Facture d'upgrade : prix plein du nouveau plan, remise = reliquat non consommé. */
    private function createUpgradeInvoice(Subscription $sub, SubscriptionPlan $target, SubscriptionPlanChange $change): Invoice
    {
        $settings = PlatformSetting::get();
        $gross    = round((float) $change->amount_due + (float) $change->credit_applied, 3);
        $net      = (float) $change->amount_due;
        $tax      = round($net * ((float) $settings->tax_rate) / 100, 3) + (float) $settings->timbre_fiscal;

        $creditLine = (float) $change->credit_applied > 0
            ? sprintf(
                ' Remise de %s TND correspondant au reliquat non consommé de votre période en cours.',
                number_format((float) $change->credit_applied, 3, ',', ' '),
            )
            : '';

        $invoice = Invoice::create([
            'subscription_id' => $sub->id,
            'hotel_id'        => null,
            'invoice_number'  => $this->billing->nextInvoiceNumber(),
            'amount'          => $gross,
            'tax_amount'      => $tax,
            'discount_amount' => (float) $change->credit_applied,
            'total_amount'    => round($net + $tax, 3),
            'currency'        => 'TND',
            'status'          => 'sent',
            'due_at'          => now()->addDays(7),
            'notes'           => sprintf(
                '%s (%s). Nouvelle période complète à compter du paiement.%s',
                $change->kind === SubscriptionPlanChange::KIND_SUBSCRIBE
                    ? sprintf('Souscription — %s', $target->name)
                    : sprintf('Changement de plan — %s → %s', $sub->plan?->name ?? '—', $target->name),
                $sub->billing_cycle === 'yearly' ? 'annuel' : 'mensuel',
                $creditLine,
            ),
            'metadata'        => [
                'plan_change'    => true,
                'plan_change_id' => $change->id,
                'from_plan'      => $sub->plan?->slug,
                'to_plan'        => $target->slug,
                'credit_applied' => (string) $change->credit_applied,
                'tax_rate'       => (string) $settings->tax_rate,
                'timbre_fiscal'  => (string) $settings->timbre_fiscal,
            ],
        ]);

        AuditLogger::log('invoice.plan_change_generated', $invoice, newValues: [
            'invoice_number' => $invoice->invoice_number,
            'total_amount'   => (string) $invoice->total_amount,
            'to_plan'        => $target->slug,
        ]);

        return $invoice;
    }

    // ─── Application ─────────────────────────────────────────────────────────

    /**
     * Appelé quand une facture vient d'être payée (tous canaux confondus, via
     * BillingService::handleInvoicePaid). Si elle portait un upgrade, le plan
     * bascule maintenant — et pas une seconde avant.
     *
     * Idempotent : un changement déjà appliqué est ignoré, si bien qu'un
     * webhook rejoué ou une double vérification de paiement ne rebascule
     * jamais le plan ni ne prolonge deux fois la période.
     */
    public function applyPaidUpgrade(Invoice $invoice): void
    {
        $changeId = $invoice->metadata['plan_change_id'] ?? null;
        if (!$changeId) {
            return;
        }

        DB::transaction(function () use ($changeId) {
            $change = SubscriptionPlanChange::whereKey($changeId)->lockForUpdate()->first();
            if (!$change || $change->status !== SubscriptionPlanChange::STATUS_PENDING_PAYMENT) {
                return; // déjà appliqué, annulé, ou inconnu — rien à refaire
            }

            $this->applyChange($change, 'upgrade_applied');
        });
    }

    /**
     * Applique les downgrades arrivés à échéance. Lancé par la commande
     * `subscriptions:apply-plan-changes` (quotidienne).
     *
     * @return int nombre de changements appliqués
     */
    public function applyDue(): int
    {
        $applied = 0;

        SubscriptionPlanChange::with('subscription')
            ->where('status', SubscriptionPlanChange::STATUS_SCHEDULED)
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->chunkById(100, function ($changes) use (&$applied) {
                foreach ($changes as $change) {
                    try {
                        DB::transaction(function () use ($change, &$applied) {
                            $fresh = SubscriptionPlanChange::whereKey($change->id)->lockForUpdate()->first();
                            if (!$fresh || $fresh->status !== SubscriptionPlanChange::STATUS_SCHEDULED) {
                                return;
                            }
                            $this->applyChange($fresh, 'downgrade_applied');
                            $applied++;
                        });
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('[billing] downgrade non appliqué', [
                            'plan_change_id' => $change->id,
                            'error'          => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $applied;
    }

    /**
     * Bascule effective du plan. Point unique — upgrade payé comme downgrade
     * échu passent tous les deux par ici.
     *
     * Un upgrade ouvre une nouvelle période complète (le client vient de la
     * payer) ; un downgrade s'enchaîne sur la période suivante sans rien
     * retirer de ce qui a déjà été consommé.
     */
    private function applyChange(SubscriptionPlanChange $change, string $eventType): void
    {
        $sub    = $change->subscription()->with('plan')->first();
        $target = $change->toPlan()->first();
        if (!$sub || !$target) {
            return;
        }

        $previousPlanId = $sub->plan_id;
        $previousStatus = $sub->status;
        $updates        = ['plan_id' => $target->id];

        // Le client a explicitement accepté de quitter ses conditions
        // historiques : elles sont levées ici, et seulement ici.
        if ($change->conditions_change_accepted) {
            $metadata  = $sub->metadata ?? [];
            $overrides = (array) ($metadata['feature_overrides'] ?? []);
            unset($overrides['checkins_per_month']);
            $metadata['feature_overrides'] = $overrides;

            $updates['metadata']       = $metadata;
            $updates['custom_price']   = null;
            $updates['is_legacy_plan'] = false;
        }

        if (in_array($change->kind, SubscriptionPlanChange::PAID_UPFRONT, true)) {
            // Nouvelle période complète : le client vient de la régler.
            $updates['expires_at'] = $sub->billing_cycle === 'yearly'
                ? now()->addYear()
                : now()->addMonthNoOverflow();
            $updates['started_at'] = now();
            // Un achat payé relance un compte retombé (essai, expiré, suspendu).
            if (in_array($sub->status, ['expired', 'suspended', 'trial', 'trial_expired'], true)) {
                $updates['status']           = 'active';
                $updates['suspended_at']     = null;
                $updates['suspended_reason'] = null;
            }

            // Le client vient de payer pour continuer : on émettra donc sa
            // prochaine facture à l'échéance. Sans cela, un essai converti
            // n'aurait plus JAMAIS de facture et s'éteindrait en silence.
            // On ne réactive rien si le client a explicitement résilié —
            // payer sa dernière échéance n'est pas se réabonner.
            if (!$sub->cancellation_requested_at) {
                $updates['auto_renew'] = true;
            }

            // La facture de renouvellement de la période qu'on vient de
            // remplacer n'a plus d'objet : la laisser ouverte la ferait
            // relancer, puis suspendre un client parfaitement à jour.
            $this->voidSupersededRenewals($sub, $change->invoice_id);
        }

        $sub->update($updates);
        $change->update([
            'status'     => SubscriptionPlanChange::STATUS_APPLIED,
            'applied_at' => now(),
        ]);

        SubscriptionEvent::create([
            'subscription_id'  => $sub->id,
            'event_type'       => $eventType,
            'previous_status'  => $previousStatus,
            'new_status'       => $sub->fresh()->status,
            'previous_plan_id' => $previousPlanId,
            'new_plan_id'      => $target->id,
            'notes'            => sprintf(
                '%s vers %s (%s).',
                match ($change->kind) {
                    SubscriptionPlanChange::KIND_SUBSCRIBE => 'Souscription',
                    SubscriptionPlanChange::KIND_UPGRADE   => 'Upgrade',
                    default                                => 'Downgrade',
                },
                $target->name,
                in_array($change->kind, SubscriptionPlanChange::PAID_UPFRONT, true)
                    ? number_format((float) $change->amount_due, 3, '.', '').' TND réglés'
                    : 'effet au cycle suivant',
            ),
            'performed_by'     => $change->requested_by,
            'created_at'       => now(),
        ]);

        AuditLogger::log("subscription.{$eventType}", $sub,
            oldValues: ['plan_id' => $previousPlanId],
            newValues: ['plan_id' => $target->id, 'expires_at' => (string) $sub->fresh()->expires_at],
        );

        $this->forgetCaches($sub);
        $this->notifyApplied($sub->fresh(['plan', 'organization']), $change, $target);
    }

    /** Annule un changement encore en cours (facture éventuelle annulée avec). */
    public function cancelPending(Subscription $sub, ?User $actor): ?SubscriptionPlanChange
    {
        return DB::transaction(function () use ($sub, $actor) {
            $change = SubscriptionPlanChange::where('subscription_id', $sub->id)
                ->whereIn('status', SubscriptionPlanChange::IN_FLIGHT)
                ->lockForUpdate()
                ->first();

            if (!$change) {
                return null;
            }

            // La facture d'upgrade n'a plus d'objet : on l'annule (statut
            // `void`) au lieu de la laisser vivre et déclencher des relances.
            // Une facture DÉJÀ payée n'est jamais touchée.
            $invoice = $change->invoice()->first();
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->update(['status' => 'void']);
            }

            $change->update(['status' => SubscriptionPlanChange::STATUS_CANCELLED]);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'plan_change_cancelled',
                'previous_status' => $sub->status,
                'new_status'      => $sub->status,
                'notes'           => 'Changement de plan annulé par le client.',
                'performed_by'    => $actor?->id,
                'created_at'      => now(),
            ]);
            AuditLogger::log('subscription.plan_change_cancelled', $sub, newValues: ['plan_change_id' => $change->id]);

            return $change;
        });
    }

    // ─── Résiliation / reprise ───────────────────────────────────────────────

    /**
     * Résiliation : on arrête le RENOUVELLEMENT, on ne coupe pas le service.
     * Rien n'est supprimé — ni les factures, ni les check-ins, ni l'usage.
     * Le client garde l'accès jusqu'au terme qu'il a payé.
     */
    public function cancelRenewal(Subscription $sub, ?User $actor, ?string $reason = null): Subscription
    {
        if (!$sub->auto_renew && $sub->cancellation_requested_at) {
            return $sub; // déjà résilié — idempotent, pas d'erreur ni de second email
        }

        $sub->update([
            'auto_renew'                => false,
            'cancellation_requested_at' => now(),
            'cancellation_reason'       => $reason,
        ]);

        SubscriptionEvent::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'renewal_cancelled',
            'previous_status' => $sub->status,
            'new_status'      => $sub->status,
            'notes'           => 'Renouvellement arrêté par le client. Accès maintenu jusqu\'au '
                .($sub->expires_at?->format('d/m/Y') ?? '—').'.'
                .($reason ? " Motif : {$reason}" : ''),
            'performed_by'    => $actor?->id,
            'created_at'      => now(),
        ]);
        AuditLogger::log('subscription.renewal_cancelled', $sub, newValues: ['expires_at' => (string) $sub->expires_at]);

        $this->notifyClient($sub, 'subscription_cancelled', [
            'expires_at' => $sub->expires_at?->format('d/m/Y') ?? '—',
        ]);
        $this->notifyAdmin($sub, 'Résiliation', $reason);

        return $sub->fresh();
    }

    /** Reprise du renouvellement — self-service, tant que la période court encore. */
    public function reactivateRenewal(Subscription $sub, ?User $actor): Subscription
    {
        if ($sub->auto_renew && !$sub->cancellation_requested_at) {
            return $sub; // déjà actif — idempotent
        }

        $sub->update([
            'auto_renew'                => true,
            'cancellation_requested_at' => null,
            'cancellation_reason'       => null,
        ]);

        SubscriptionEvent::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'renewal_reactivated',
            'previous_status' => $sub->status,
            'new_status'      => $sub->status,
            'notes'           => 'Renouvellement rétabli par le client.',
            'performed_by'    => $actor?->id,
            'created_at'      => now(),
        ]);
        AuditLogger::log('subscription.renewal_reactivated', $sub);

        $this->notifyClient($sub, 'subscription_reactivated', [
            'expires_at' => $sub->expires_at?->format('d/m/Y') ?? '—',
        ]);

        return $sub->fresh();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function recordEvent(Subscription $sub, SubscriptionPlanChange $change, ?User $actor): void
    {
        $target = $change->toPlan()->first();
        $amount = number_format((float) $change->amount_due, 3, '.', '');

        $eventType = match ($change->kind) {
            SubscriptionPlanChange::KIND_SUBSCRIBE => 'subscription_requested',
            SubscriptionPlanChange::KIND_UPGRADE   => 'upgrade_requested',
            default                                => 'downgrade_scheduled',
        };

        SubscriptionEvent::create([
            'subscription_id'  => $sub->id,
            'event_type'       => $eventType,
            'previous_status'  => $sub->status,
            'new_status'       => $sub->status,
            'previous_plan_id' => $sub->plan_id,
            'new_plan_id'      => $change->to_plan_id,
            'notes'            => match ($change->kind) {
                SubscriptionPlanChange::KIND_SUBSCRIBE => sprintf('Souscription à %s demandée — %s TND à régler.', $target?->name, $amount),
                SubscriptionPlanChange::KIND_UPGRADE   => sprintf('Upgrade vers %s demandé — %s TND à régler.', $target?->name, $amount),
                default                                => sprintf('Downgrade vers %s programmé pour le %s.', $target?->name, $change->effective_at?->format('d/m/Y')),
            },
            'performed_by'     => $actor?->id,
            'created_at'       => now(),
        ]);

        AuditLogger::log(
            "subscription.{$eventType}",
            $sub,
            newValues: ['to_plan' => $target?->slug, 'amount_due' => (string) $change->amount_due],
        );
    }

    /**
     * Annule les factures de renouvellement rendues sans objet par un achat
     * qui vient d'ouvrir une nouvelle période complète.
     *
     * Sans cela, le client garde en travers une facture correspondant à une
     * période qu'il vient de racheter : elle part en relance, puis finit par
     * le faire suspendre alors qu'il est à jour.
     */
    private function voidSupersededRenewals(Subscription $sub, ?string $exceptInvoiceId): void
    {
        Invoice::where('subscription_id', $sub->id)
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->where('metadata->renewal', true)
            ->when($exceptInvoiceId, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
            ->get()
            ->each(function (Invoice $invoice) {
                $invoice->update(['status' => 'void']);
                AuditLogger::log('invoice.voided_superseded', $invoice, newValues: [
                    'reason' => 'Période rachetée par un changement de plan réglé.',
                ]);
            });
    }

    private function forgetCaches(Subscription $sub): void
    {
        if ($sub->hotel_id) {
            Cache::forget("hotel_subscription_active:{$sub->hotel_id}");
        }
        if ($sub->organization_id) {
            Cache::forget("org_subscription_active:{$sub->organization_id}");
        }
    }

    private function notifyApplied(Subscription $sub, SubscriptionPlanChange $change, SubscriptionPlan $target): void
    {
        $this->notifyClient($sub, $change->kind === SubscriptionPlanChange::KIND_SUBSCRIBE
            ? 'subscription_activated' : 'plan_change_applied', [
            'plan_name'  => $target->name,
            'expires_at' => $sub->expires_at?->format('d/m/Y') ?? '—',
        ]);
    }

    /** Email client — jamais bloquant : un souci d'envoi ne doit pas annuler un changement de plan. */
    private function notifyClient(Subscription $sub, string $template, array $vars): void
    {
        try {
            $org    = $sub->organization;
            $locale = $org?->locale ?? \App\Models\EmailTemplate::DEFAULT_LOCALE;

            SystemMailer::send($template, $org?->contact_email, array_merge([
                'name'       => $org?->name ?? $sub->hotel?->name ?? 'Client Qayed',
                'plan_name'  => $sub->plan?->name ?? '—',
                'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), SystemMailer::label('view_subscriptions', $locale)),
            ], $vars), $locale);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[billing] email de changement de plan non envoyé', [
                'subscription_id' => $sub->id, 'template' => $template, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAdmin(Subscription $sub, string $title, ?string $reason): void
    {
        try {
            $org = $sub->organization;
            \App\Services\Notifications\AdminNotifier::notify(
                "{$title} : ".($org?->name ?? 'Client Qayed'),
                '<h2 style="margin:0 0 12px">'.e($title).'</h2><table style="font-size:14px;border-collapse:collapse">'
                .\App\Services\Notifications\AdminNotifier::row('Hébergeur', $org?->name)
                .\App\Services\Notifications\AdminNotifier::row('Plan', $sub->plan?->name)
                .\App\Services\Notifications\AdminNotifier::row('Fin de service', $sub->expires_at?->format('d/m/Y'))
                .\App\Services\Notifications\AdminNotifier::row('Motif', $reason)
                .'</table><p style="margin-top:16px">Information — aucune action requise.</p>',
            );
        } catch (\Throwable) {
            // L'information de l'admin n'est jamais bloquante.
        }
    }

    /** @throws HttpResponseException */
    private function fail(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'data'   => null,
            'errors' => [['code' => $code, 'message' => $message, 'field' => null]],
        ], 422));
    }
}
