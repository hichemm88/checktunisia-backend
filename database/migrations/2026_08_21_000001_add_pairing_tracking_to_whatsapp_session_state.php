<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Suivi de l'appairage, pour la montée en charge progressive après changement
 * de numéro émetteur. Sans ces deux colonnes, rien ne distinguait « numéro en
 * service depuis six mois » de « numéro appairé il y a dix minutes » — et c'est
 * précisément le second qui se fait restreindre par Meta lorsqu'il vide un
 * arriéré de fiches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            // Numéro réellement connecté, rapporté par le worker (chiffres
            // internationaux, sans '+'). Sert à détecter un CHANGEMENT de
            // numéro : une simple reconnexion ne remet pas la réputation à zéro.
            $table->string('phone_number', 32)->nullable();

            // Début de la fenêtre de montée en charge.
            $table->timestamp('paired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'paired_at']);
        });
    }
};
