<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API v1.2 : réceptionniste de la DERNIÈRE demande de widget_url, distinct de
 * celui qui tient la saisie (operator_*). Quand une seconde personne ouvre une
 * fiche déjà en cours de saisie, sa demande est notée ici sans retirer la
 * main à la première ; elle ne devient l'opérateur qu'en ouvrant réellement le
 * widget (bootstrap). Colonnes facultatives, aucune session existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->uuid('requested_operator_user_id')->nullable();
            $table->string('requested_operator_name', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->dropColumn(['requested_operator_user_id', 'requested_operator_name']);
        });
    }
};
