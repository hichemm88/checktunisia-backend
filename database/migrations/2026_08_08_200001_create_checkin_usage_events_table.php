<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre de consommation des check-ins facturables (ledger).
 *
 * Remplace le COUNT recalculé sur `check_ins` comme source de vérité du
 * quota. Deux raisons, toutes deux des défauts constatés de l'ancien
 * comptage :
 *
 *  1. Le COUNT prenait les BROUILLONS (`status != 'cancelled'` inclut
 *     `draft`) : une fiche commencée puis abandonnée consommait du quota
 *     et pouvait être facturée. Ici, une ligne n'existe QUE si la fiche a
 *     été finalisée (brouillon → active, `CheckInService::complete`), qui
 *     est l'acte déclaratif réellement rendu.
 *  2. Le COUNT était MUTABLE : le passé bougeait au gré des changements de
 *     statut. Une ligne de ce registre est posée une fois et n'est jamais
 *     retirée — l'historique de facturation est immuable (une annulation
 *     postérieure est horodatée dans `cancelled_at`, à titre informatif,
 *     et ne rend pas la consommation).
 *
 * IDEMPOTENCE : l'unicité porte sur `check_in_id`. Double clic, rejeu HTTP,
 * timeout suivi d'un retry, job relancé, webhook répété — quel que soit le
 * chemin, une fiche ne peut consommer qu'une seule fois, définitivement.
 * C'est la base de données qui le garantit, pas le code applicatif.
 *
 * `period` (1er du mois calendaire) est figé à la finalisation : le cycle
 * de comptage reste le mois calendaire, mais il est désormais ancré sur la
 * DÉCLARATION, plus sur la création du brouillon (une fiche commencée le
 * 31 janvier et finalisée le 2 février est une déclaration de février).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkin_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('check_in_id')->constrained('check_ins')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->date('period');                       // 1er jour du mois calendaire de la déclaration
            $table->timestamp('consumed_at');             // horodatage de la finalisation
            $table->timestamp('cancelled_at')->nullable(); // annulation postérieure (informatif — ne rend pas la consommation)
            $table->timestamps();

            // La garantie d'idempotence : une fiche = au plus une consommation.
            $table->unique('check_in_id', 'uq_checkin_usage_events_check_in');
            // Comptage mensuel par organisation (requête chaude du dashboard).
            $table->index(['organization_id', 'period'], 'idx_checkin_usage_events_org_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_usage_events');
    }
};
