<?php

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grille tarifaire V2 — migration de DONNÉES (aucune donnée supprimée).
 *
 * 1. GRANDFATHERING d'abord : tous les abonnements existants au moment du
 *    déploiement sont marqués `is_legacy_plan` et leur quota de check-ins
 *    est FIGÉ via subscription.metadata.feature_overrides (le mécanisme
 *    d'overrides négociés existant) :
 *      - essentiel  → 100 (leur plan affichait déjà « 100 check-ins / mois »)
 *      - tout autre → -1  (les anciens Pro/Multi-sites restent ILLIMITÉS,
 *        même si le plan Pro passe à 600 pour les nouveaux comptes)
 *    Un compte legacy n'est jamais facturé de dépassement automatiquement
 *    (CheckinQuota::overageBillable) et conserve son prix (custom_price ou
 *    prix du plan, inchangés ici).
 *
 * 2. Puis application de la grille V2 sur les plans :
 *      - Essentiel : 59 TND, quota 100, dépassement +10 TND / tranche de 50
 *      - Pro       : 119 TND, quota 600 (plus illimité), même dépassement
 *      - Hôtel     : 299 TND (NOUVEAU), illimité, option multi-établissements
 *                    +99 TND/mois par établissement supplémentaire
 *      - Multi-sites : retiré de la grille publique (is_public=false), non
 *                    souscriptible, conservé tel quel pour ses abonnés
 */
return new class extends Migration
{
    public function up(): void
    {
        // Base vierge (installation neuve, tests RefreshDatabase) : rien à
        // migrer — la grille V2 arrive par SubscriptionPlanSeeder.
        if (!SubscriptionPlan::exists()) {
            return;
        }

        DB::transaction(function () {
            // ── 1. Grandfathering des comptes existants ──────────────────────
            $legacyQuotaBySlug = fn (?string $slug): int => $slug === 'essentiel' ? 100 : -1;

            Subscription::with('plan')->chunkById(200, function ($subs) use ($legacyQuotaBySlug) {
                foreach ($subs as $sub) {
                    $metadata  = $sub->metadata ?? [];
                    $overrides = (array) ($metadata['feature_overrides'] ?? []);
                    if (!array_key_exists('checkins_per_month', $overrides)) {
                        $overrides['checkins_per_month'] = $legacyQuotaBySlug($sub->plan?->slug);
                    }
                    $metadata['feature_overrides'] = $overrides;

                    $sub->timestamps = false;
                    $sub->forceFill(['is_legacy_plan' => true, 'metadata' => $metadata])->save();
                }
            });

            // ── 2. Grille V2 (même source que le seeder — installs neuves) ───
            $marketing = SubscriptionPlanSeeder::marketingDefaults();
            foreach (SubscriptionPlanSeeder::planDefaults() as $plan) {
                $plan['marketing'] = $marketing[$plan['slug']] ?? null;
                SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], $plan);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Grille V1 : Pro redevient illimité, Multi-sites redevient public,
            // le plan Hôtel est retiré s'il n'a aucun abonné.
            $hotel = SubscriptionPlan::where('slug', 'hotel')->withCount('subscriptions')->first();
            if ($hotel && $hotel->subscriptions_count === 0) {
                $hotel->delete();
            }

            SubscriptionPlan::where('slug', 'multi-sites')->update(['is_public' => true, 'sort_order' => 3]);

            foreach (['essentiel', 'pro'] as $slug) {
                $plan = SubscriptionPlan::where('slug', $slug)->first();
                if (!$plan) {
                    continue;
                }
                $features = (array) $plan->features;
                unset($features['checkins_per_month']);
                $plan->update([
                    'features'            => $features,
                    'overage_price'       => null,
                    'overage_bundle_size' => null,
                    'marketing'           => array_merge((array) $plan->marketing, self::V1_MARKETING[$slug]),
                ]);
            }

            // Dé-grandfathering : retire uniquement ce que up() a posé.
            Subscription::where('is_legacy_plan', true)->chunkById(200, function ($subs) {
                foreach ($subs as $sub) {
                    $metadata  = $sub->metadata ?? [];
                    $overrides = (array) ($metadata['feature_overrides'] ?? []);
                    unset($overrides['checkins_per_month']);
                    $metadata['feature_overrides'] = $overrides;

                    $sub->timestamps = false;
                    $sub->forceFill(['is_legacy_plan' => false, 'metadata' => $metadata])->save();
                }
            });
        });
    }

    /** Contenus marketing V1 (taglines/bullets d'avant la grille V2) pour un rollback fidèle. */
    private const V1_MARKETING = [
        'essentiel' => [
            'tagline' => [
                'fr' => "Pour démarrer — petits hébergements avec un volume modéré d'arrivées.",
                'en' => 'To get started — small properties with a moderate flow of arrivals.',
                'ar' => 'للانطلاق — مؤسسات إقامة صغيرة بعدد وافدين معتدل.',
            ],
            'bullets' => [
                ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                ['included' => true,  'text' => ['fr' => '100 check-ins / mois', 'en' => '100 check-ins / month', 'ar' => '100 تسجيل وصول / شهريًا']],
                ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                ['included' => true,  'text' => ['fr' => '2 comptes utilisateurs', 'en' => '2 user accounts', 'ar' => 'حسابان للمستخدمين']],
                ['included' => true,  'text' => ['fr' => 'Historique 12 mois', 'en' => '12-month history', 'ar' => 'سجل 12 شهرًا']],
                ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
                ['included' => false, 'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
            ],
        ],
        'pro' => [
            'tagline' => [
                'fr' => "Pour les hôtels et maisons d'hôtes avec un flux régulier d'arrivées.",
                'en' => 'For hotels and guest houses with a steady flow of arrivals.',
                'ar' => 'للفنادق ودور الضيافة ذات تدفق منتظم من الوافدين.',
            ],
            'bullets' => [
                ['included' => true,  'text' => ['fr' => '1 établissement', 'en' => '1 property', 'ar' => 'مؤسسة واحدة']],
                ['included' => true,  'text' => ['fr' => 'Check-ins illimités', 'en' => 'Unlimited check-ins', 'ar' => 'تسجيلات وصول غير محدودة']],
                ['included' => true,  'text' => ['fr' => 'Scan MRZ passeport & CIN', 'en' => 'Passport & ID MRZ scan', 'ar' => 'مسح MRZ لجواز السفر وبطاقة التعريف']],
                ['included' => true,  'text' => ['fr' => 'Fiche de police imprimable', 'en' => 'Printable police form', 'ar' => 'بطاقة شرطة قابلة للطباعة']],
                ['included' => true,  'text' => ['fr' => '5 comptes utilisateurs', 'en' => '5 user accounts', 'ar' => '5 حسابات للمستخدمين']],
                ['included' => true,  'text' => ['fr' => 'Historique illimité', 'en' => 'Unlimited history', 'ar' => 'سجل غير محدود']],
                ['included' => true,  'text' => ['fr' => 'Export CSV nuitées', 'en' => 'Overnight stays CSV export', 'ar' => 'تصدير CSV لليالي المبيت']],
                ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => 'Multi-property', 'ar' => 'تعدد المؤسسات']],
            ],
        ],
    ];
};
