<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des quotas de check-ins (grille V2).
 *
 * - `quota_alerts` : anti-spam des alertes — chaque seuil (80 %, 100 %,
 *   suggestion d'upsell) ne part qu'UNE fois par organisation et par mois
 *   calendaire (period = 1er du mois). Le compteur repart naturellement au
 *   mois suivant (nouvelle period).
 * - `overage_charges` : une ligne par organisation et par mois clôturé en
 *   dépassement — calcul des tranches, montant, et lien vers la facture
 *   générée (comptes non-legacy uniquement ; les comptes legacy sont
 *   calculés pour le pilotage admin mais jamais facturés automatiquement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->date('period');                       // 1er jour du mois calendaire concerné
            $table->string('threshold', 20);              // warn_80 | reached_100 | upsell_suggested
            $table->unsignedInteger('checkins_count')->default(0);
            $table->unsignedInteger('quota')->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['organization_id', 'period', 'threshold'], 'uq_quota_alerts_org_period_threshold');
        });

        Schema::create('overage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->date('period');                       // 1er jour du mois clôturé
            $table->unsignedInteger('checkins_count');
            $table->unsignedInteger('quota');
            $table->unsignedInteger('overage_count');     // check-ins au-delà du quota
            $table->unsignedSmallInteger('bundle_size');  // taille de tranche au moment du calcul
            $table->unsignedSmallInteger('bundle_count'); // tranches entamées
            $table->decimal('unit_price', 8, 3);          // prix par tranche au moment du calcul
            $table->decimal('amount', 10, 3);             // bundle_count × unit_price
            $table->string('status', 20)->default('pending'); // pending | invoiced | excluded_legacy | waived
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'period'], 'uq_overage_charges_org_period');
            $table->index(['period', 'status'], 'idx_overage_charges_period_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overage_charges');
        Schema::dropIfExists('quota_alerts');
    }
};
