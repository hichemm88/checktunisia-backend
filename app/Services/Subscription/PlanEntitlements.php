<?php

namespace App\Services\Subscription;

use App\Models\DocumentScan;
use App\Models\Organization;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Source de vérité UNIQUE des fonctionnalités effectives d'un client.
 *
 * L'admin pilote, l'app applique :
 *  - les limites/fonctions vivent dans subscription_plans.features (éditées
 *    dans Admin > Abonnements) ;
 *  - un deal négocié par client peut les surcharger via
 *    subscription.metadata.feature_overrides (édité sur la fiche hébergeur) ;
 *  - la valeur effective = override si présent, sinon valeur du pack, sinon
 *    défaut. -1 ou null = illimité (convention historique du seeder).
 *
 * Clés canoniques (n'inventer une clé que si l'app a un point d'application) :
 *  - max_properties       : nb d'établissements de l'organisation
 *  - max_users            : nb d'utilisateurs de l'organisation
 *  - max_rooms            : nb total de chambres (colonne dédiée du plan,
 *                           historique — exposée ici pour l'UI, appliquée
 *                           dans OrganizationController)
 *  - ocr_scans_per_month  : scans de documents par mois calendaire
 *  - whatsapp_relay       : relais des fiches police vers WhatsApp (bool)
 *
 * Web et mobile passent par les mêmes endpoints : l'application côté serveur
 * vaut pour les deux (règle transverse n°1).
 */
class PlanEntitlements
{
    /** Défauts si le pack ne précise rien (comportement historique : tout ouvert). */
    public const DEFAULTS = [
        'max_properties'      => null,
        'max_users'           => null,
        'max_rooms'           => null,
        'ocr_scans_per_month' => null,
        'whatsapp_relay'      => true,
    ];

    /** Libellés FR servis à l'admin (grille d'édition) et aux messages d'erreur. */
    public const LABELS = [
        'max_properties'      => 'Établissements',
        'max_users'           => 'Utilisateurs',
        'max_rooms'           => 'Chambres (total)',
        'ocr_scans_per_month' => 'Scans OCR / mois',
        'whatsapp_relay'      => 'Relais WhatsApp police',
    ];

    private const LIMIT_KEYS = ['max_properties', 'max_users', 'max_rooms', 'ocr_scans_per_month'];
    private const TOGGLE_KEYS = ['whatsapp_relay'];

    // ─── Résolution ──────────────────────────────────────────────────────────

    /**
     * Carte effective { clé → valeur } pour une organisation.
     * Sans abonnement actif : défauts (les middlewares d'abonnement bloquent
     * déjà l'accès en amont — on ne double pas ce contrôle ici).
     */
    public static function resolve(Organization $org): array
    {
        $sub  = $org->activeSubscription()->with('plan')->first();
        $plan = $sub?->plan;

        // Plafond d'établissements dérivé de la TARIFICATION : un pack sans
        // prix par établissement supplémentaire est plafonné à ses inclus ;
        // avec un prix par supplément (Multi-sites), AUCUN plafond — seul le
        // prix évolue. Une valeur explicite dans features prime.
        $derived = [];
        if ($plan) {
            $derived['max_properties'] = $plan->extra_property_price === null
                ? max(1, (int) ($plan->included_properties ?? 1))
                : -1;
        }

        $fromPlan = array_merge(
            $derived,
            (array) ($plan?->features ?? []),
            // max_rooms vit dans une colonne dédiée du plan (historique).
            $plan && $plan->max_rooms !== null ? ['max_rooms' => (int) $plan->max_rooms] : [],
        );
        $overrides = (array) ($sub?->metadata['feature_overrides'] ?? []);

        $effective = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $overrides) ? $overrides[$key]
                : (array_key_exists($key, $fromPlan) ? $fromPlan[$key] : $default);

            if (in_array($key, self::LIMIT_KEYS, true)) {
                // -1, null, '' → illimité ; sinon entier >= 0.
                $effective[$key] = ($value === null || $value === '' || (int) $value < 0) ? null : (int) $value;
            } else {
                $effective[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $effective;
    }

    /** Limite effective (null = illimité). */
    public static function limit(Organization $org, string $key): ?int
    {
        return self::resolve($org)[$key] ?? null;
    }

    /** Fonction booléenne active ? */
    public static function allows(Organization $org, string $key): bool
    {
        return (bool) (self::resolve($org)[$key] ?? false);
    }

    // ─── Usage réel ──────────────────────────────────────────────────────────

    /** Consommation courante pour chaque limite (affichée dans l'admin et le front). */
    public static function usage(Organization $org): array
    {
        return [
            'max_properties'      => $org->properties()->count(),
            // Le staff est rattaché aux établissements via le pivot (sans
            // organization_id) : compter les deux populations, sans doublon.
            'max_users'           => \App\Models\User::where(fn ($q) => $q
                    ->where('organization_id', $org->id)
                    ->orWhereHas('hotels', fn ($h) => $h->where('organization_id', $org->id)))
                ->count(),
            'max_rooms'           => $org->totalRooms(),
            'ocr_scans_per_month' => self::scansThisMonth($org),
        ];
    }

    public static function scansThisMonth(Organization $org): int
    {
        return DocumentScan::whereHas('checkIn.hotel', fn ($q) => $q->where('organization_id', $org->id))
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** Payload complet { clé → { limit, used, label } } pour les UIs. */
    public static function summary(Organization $org): array
    {
        $effective = self::resolve($org);
        $usage     = self::usage($org);

        $out = [];
        foreach (self::LIMIT_KEYS as $key) {
            $out[$key] = ['limit' => $effective[$key], 'used' => $usage[$key], 'label' => self::LABELS[$key]];
        }
        foreach (self::TOGGLE_KEYS as $key) {
            $out[$key] = ['enabled' => $effective[$key], 'label' => self::LABELS[$key]];
        }

        return $out;
    }

    // ─── Application (points de passage) ─────────────────────────────────────

    /**
     * Bloque (422 PLAN_LIMIT) si ajouter $adding unités dépasserait la limite.
     * $used est passé par l'appelant quand il le connaît déjà (évite un COUNT).
     */
    public static function assertWithinLimit(Organization $org, string $key, ?int $used = null, int $adding = 1): void
    {
        $limit = self::limit($org, $key);
        if ($limit === null) {
            return;
        }

        $used ??= self::usage($org)[$key] ?? 0;
        if ($used + $adding <= $limit) {
            return;
        }

        $label = self::LABELS[$key] ?? $key;
        throw new HttpResponseException(response()->json([
            'data'   => null,
            'errors' => [[
                'code'    => 'PLAN_LIMIT',
                'message' => "Limite de votre pack atteinte — {$label} : {$used}/{$limit}. Passez à un pack supérieur ou contactez Qayed.",
                'field'   => $key,
            ]],
        ], 422));
    }
}
