<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 *
 * Horodatage de la dernière reprise demandée par un administrateur. Le worker a
 * sa propre veille technique (30 min après échecs répétés), interne à son
 * processus : le bouton « Reprendre » ne la levait pas et rien d'autre ne le
 * pouvait. Relayé par control(), ce timestamp permet au worker de constater
 * qu'une reprise a été demandée APRÈS le début de sa veille, et de la lever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            $table->timestamp('resume_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            $table->dropColumn('resume_requested_at');
        });
    }
};
