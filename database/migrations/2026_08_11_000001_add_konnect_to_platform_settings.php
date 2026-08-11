<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passerelle de paiement Konnect, en remplacement de Flouci.
 *
 * Les colonnes Flouci restent en place : les paiements déjà encaissés par ce
 * canal doivent continuer à se vérifier et à s'afficher dans le journal. Le
 * canal, lui, se ferme par son drapeau — on ne supprime pas l'historique d'un
 * moyen de paiement pour en ouvrir un autre.
 *
 * `konnect_api_key` est en `text` et non en `string(255)` : elle est CHIFFRÉE
 * par le cast Eloquent, et un chiffré est plus long que son clair. Les colonnes
 * Flouci, elles, sont restées en clair — dette connue, hors périmètre ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('konnect_enabled')->default(false);
            // « sandbox » (simulation) ou « production ». Vit à côté des
            // identifiants pour que le couple (environnement, clé) reste
            // cohérent : une clé de simulation dans l'API de production ne
            // produit qu'un refus, une clé de production dans la simulation
            // n'encaisse rien.
            $table->string('konnect_environment', 20)->default('sandbox');
            $table->text('konnect_api_key')->nullable();
            $table->string('konnect_wallet_id', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'konnect_enabled',
                'konnect_environment',
                'konnect_api_key',
                'konnect_wallet_id',
            ]);
        });
    }
};
