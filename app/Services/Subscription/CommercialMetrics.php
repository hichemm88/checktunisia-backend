<?php

namespace App\Services\Subscription;

use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Photographie commerciale de la plate-forme — MRR, ARR, clients, parc.
 *
 * POURQUOI CE SERVICE EXISTE. Le tableau de bord et l'écran des KPI
 * recomposaient chacun leur base d'abonnements payants, avec le même code
 * copié deux fois. Le jour où l'un a reçu une correction et pas l'autre,
 * deux écrans du même back-office ont affiché deux chiffres (c'est ce qui
 * est arrivé à l'entonnoir des essais, qui comptait les comptes internes
 * d'un côté et pas de l'autre). ARR ne devait surtout pas devenir la
 * troisième copie.
 *
 * Toute métrique de revenu part donc d'ICI, et d'ici seulement.
 *
 * Les trois règles de comptage, en un seul endroit :
 *  1. PÉRIMÈTRE — seuls les abonnements COMMERCIAUX comptent. Un compte
 *     interne utilise le produit sans l'acheter (Organization::isCommercial) ;
 *     il n'apparaît dans aucun montant, jamais.
 *  2. UN CLIENT = UNE LIGNE — dédupliqué par organisation (ou par
 *     établissement pour les abonnements historiques). Un vieil abonnement
 *     resté « active » à côté du courant ne double pas le client.
 *  3. UN SEUL BARÈME — PlanPricing, qui porte déjà la base, les
 *     établissements supplémentaires et le prix négocié.
 */
class CommercialMetrics
{
    private const CURRENCY = 'TND';

    /**
     * Les abonnements payants en cours, un par client, du plus récent au plus
     * ancien. Base commune à tous les calculs de revenu.
     *
     * @return Collection<int, Subscription>
     */
    public function activeSubscriptions(): Collection
    {
        return Subscription::with(['plan', 'organization:id,name,billing_mode', 'hotel:id,name'])
            ->commercial()
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->get()
            ->unique(fn (Subscription $s) => $s->organization_id ?? 'hotel:'.$s->hotel_id)
            ->values();
    }

    /**
     * Revenu mensuel récurrent : la somme des abonnements payants ramenés au
     * mois (un annuel compte pour un douzième).
     */
    public function mrr(?Collection $subs = null): float
    {
        return round(($subs ?? $this->activeSubscriptions())
            ->sum(fn (Subscription $s) => PlanPricing::monthlyValue($s)), 3);
    }

    /**
     * Revenu annuel récurrent.
     *
     * Ce n'est délibérément PAS « MRR × 12 ». Un abonnement annuel est vendu
     * onze mois (un mois offert) : son ARR doit valoir le montant réellement
     * facturé, celui que le client voit sur sa facture. Mensualiser puis
     * remultiplier donnerait 648,996 TND pour une facture de 649,000 — faux,
     * et inexplicable en comptabilité.
     *
     * La cohérence avec le MRR est structurelle et non arithmétique : même
     * base d'abonnements, même barème, seule l'échelle change.
     */
    public function arr(?Collection $subs = null): float
    {
        return round(($subs ?? $this->activeSubscriptions())
            ->sum(fn (Subscription $s) => PlanPricing::annualValue($s)), 3);
    }

    /**
     * Tout ce qu'un écran d'administration doit afficher du commerce, calculé
     * en une passe.
     *
     * @return array{
     *   currency: string, mrr: float, arr: float, paying_customers: int,
     *   organizations: array{total: int, commercial: int, internal: int},
     *   breakdown: array<int, array<string, mixed>>
     * }
     */
    public function snapshot(): array
    {
        $subs = $this->activeSubscriptions();

        return [
            'currency'         => self::CURRENCY,
            'mrr'              => $this->mrr($subs),
            'arr'              => $this->arr($subs),
            'paying_customers' => $subs->count(),
            // Le parc n'est pas le revenu : les comptes internes existent
            // bien, ils sont simplement hors périmètre commercial. Les
            // afficher évite de lire une perte de clients là où il n'y en a
            // pas.
            'organizations'    => [
                'total'      => Organization::count(),
                'commercial' => Organization::commercial()->count(),
                'internal'   => Organization::internal()->count(),
            ],
            'breakdown'        => $this->breakdown($subs),
        ];
    }

    /**
     * Le détail client par client — sans lui, un MRR qui bouge n'est pas
     * explicable.
     *
     * @param  Collection<int, Subscription>  $subs
     * @return array<int, array<string, mixed>>
     */
    private function breakdown(Collection $subs): array
    {
        return $subs->map(fn (Subscription $s) => [
            'customer'      => $s->organization?->name ?? $s->hotel?->name ?? '—',
            'plan'          => $s->plan?->name ?? '—',
            'billing_cycle' => $s->billing_cycle,
            'negotiated'    => $s->custom_price !== null,
            'monthly_value' => PlanPricing::monthlyValue($s),
            'annual_value'  => PlanPricing::annualValue($s),
        ])->values()->all();
    }
}
