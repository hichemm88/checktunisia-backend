<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sous quel environnement de passerelle ce paiement a-t-il été ouvert ?
 *
 * Un lien de paiement est fabriqué par le prestataire et conservé tel quel.
 * Il porte donc en lui l'environnement du moment — simulation ou production —
 * et rien ne le disait. La réutilisation d'un paiement encore valide rendait
 * alors un lien de SIMULATION à un exploitant qui venait de basculer en
 * production : il payait sur le mauvais guichet sans qu'aucun écran ne le
 * signale.
 *
 * Nul pour les paiements antérieurs et pour les canaux sans environnement
 * (virement, Flouci).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_environment', 20)->nullable()->after('provider_tracking_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('provider_environment');
        });
    }
};
