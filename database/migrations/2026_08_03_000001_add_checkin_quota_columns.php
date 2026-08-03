<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grille tarifaire V2 — colonnes de dépassement et de visibilité.
 *
 * - Le QUOTA de check-ins vit dans subscription_plans.features
 *   (clé `checkins_per_month`, -1/null = illimité) comme les autres limites
 *   pilotées par PlanEntitlements — rien à migrer ici.
 * - Le DÉPASSEMENT est une règle de facturation, pas une limite : colonnes
 *   dédiées sur le plan (prix par tranche + taille de tranche), éditables
 *   dans Admin > Abonnements. null = pas de facturation de dépassement
 *   (plans illimités).
 * - `is_public` : un plan retiré de la grille publique (Multi-sites) reste
 *   actif pour ses abonnés existants mais n'est plus souscriptible.
 * - `subscriptions.is_legacy_plan` : grandfathering — les comptes existants
 *   conservent les conditions de l'ancienne grille (posé par la migration
 *   de données 2026_08_03_000003).
 * - `organizations.upsell_flagged_at` : badge « Candidat upsell » posé par
 *   la clôture mensuelle après 2 mois consécutifs de dépassement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('overage_price', 8, 3)->nullable()->after('extra_property_price');
            $table->unsignedSmallInteger('overage_bundle_size')->nullable()->after('overage_price');
            $table->boolean('is_public')->default(true)->after('is_active');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('is_legacy_plan')->default(false)->after('custom_price');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('upsell_flagged_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('upsell_flagged_at');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('is_legacy_plan');
        });
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['overage_price', 'overage_bundle_size', 'is_public']);
        });
    }
};
