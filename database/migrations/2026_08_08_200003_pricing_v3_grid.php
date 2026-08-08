<?php

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grille tarifaire V3 — migration de DONNÉES (aucune donnée supprimée).
 *
 * Ce que la V3 change par rapport à la V2 déployée le 2026-08-03 :
 *   - Pro         : 600 → 300 check-ins inclus
 *   - Grand Flux  : illimité → 1 000 check-ins inclus
 *   - dépassement : facturé AU CHECK-IN (tranche de 1) au lieu de
 *                   +10 TND par tranche de 50 —
 *                   Essentiel 0,600 · Pro 0,400 · Grand Flux 0,250.
 * Les prix d'abonnement (59 / 119 / 299 TND) ne bougent pas.
 *
 * Deux de ces changements RÉDUISENT ce qui est inclus. On ne les applique
 * donc jamais rétroactivement : avant de toucher aux packs, le quota
 * effectif de CHAQUE abonnement existant est FIGÉ dans
 * `subscription.metadata.feature_overrides.checkins_per_month` (le mécanisme
 * d'overrides négociés déjà en place). Un client conserve exactement ce qui
 * lui a été vendu ; seuls les nouveaux comptes reçoivent la grille V3.
 *
 * `is_legacy_plan` n'est volontairement PAS posé ici : la V2 l'utilise pour
 * signaler un compte sur l'ANCIENNE grille (badge admin, cible d'upgrade,
 * exclusion de la facturation des dépassements). Un compte figé par la V3
 * garde son quota d'origine mais reste un compte de la grille courante ;
 * le marquer legacy le priverait aussi de la facturation des dépassements
 * qu'il a acceptée. Le gel du quota suffit à le protéger.
 *
 * IDEMPOTENTE : le gel ne s'applique qu'aux abonnements sans override
 * `checkins_per_month`, et les packs passent par updateOrCreate. Relancer la
 * migration ne modifie rien de plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Base vierge (installation neuve, tests RefreshDatabase) : rien à
        // figer — la grille V3 arrive par SubscriptionPlanSeeder.
        if (!SubscriptionPlan::exists()) {
            return;
        }

        DB::transaction(function () {
            // ── 1. Gel du quota vendu, AVANT de toucher aux packs ────────────
            Subscription::with('plan')->chunkById(200, function ($subs) {
                foreach ($subs as $sub) {
                    $metadata  = $sub->metadata ?? [];
                    $overrides = (array) ($metadata['feature_overrides'] ?? []);

                    // Déjà figé (grandfathering V2, ou deal négocié) : on n'y touche pas.
                    if (array_key_exists('checkins_per_month', $overrides)) {
                        continue;
                    }

                    // Quota effectif AVANT la V3 = valeur du pack ; -1/null = illimité.
                    $planQuota = $sub->plan?->features['checkins_per_month'] ?? null;
                    $overrides['checkins_per_month'] = ($planQuota === null || (int) $planQuota < 0)
                        ? -1
                        : (int) $planQuota;

                    $metadata['feature_overrides'] = $overrides;

                    $sub->timestamps = false;
                    $sub->forceFill(['metadata' => $metadata])->save();
                }
            });

            // ── 2. Grille V3 (même source que le seeder — installs neuves) ───
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
            // Retour à la grille V2 : quotas Pro 600 / Grand Flux illimité et
            // dépassement +10 TND par tranche de 50.
            foreach (self::V2_PLANS as $slug => $attrs) {
                $plan = SubscriptionPlan::where('slug', $slug)->first();
                if (!$plan) {
                    continue;
                }
                $features = (array) $plan->features;
                $features['checkins_per_month'] = $attrs['quota'];
                $plan->update([
                    'features'            => $features,
                    'overage_price'       => $attrs['overage_price'],
                    'overage_bundle_size' => $attrs['overage_bundle_size'],
                ]);
            }

            // Les gels de quota posés par up() ne sont PAS retirés : on ne
            // peut pas distinguer ici un gel V3 d'un deal négocié, et retirer
            // un override ne peut que dégrader ce qu'un client a acheté.
        });
    }

    /** Paramètres de quota/dépassement de la grille V2, pour un rollback fidèle. */
    private const V2_PLANS = [
        'essentiel' => ['quota' => 100,  'overage_price' => 10.000, 'overage_bundle_size' => 50],
        'pro'       => ['quota' => 600,  'overage_price' => 10.000, 'overage_bundle_size' => 50],
        'hotel'     => ['quota' => -1,   'overage_price' => null,   'overage_bundle_size' => null],
    ];
};
