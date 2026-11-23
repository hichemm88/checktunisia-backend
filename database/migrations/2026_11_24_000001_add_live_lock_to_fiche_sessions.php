<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verrou de saisie vivant (correctif du 23/11/2026) : le verrou ne se déduit
 * plus du TTL du token de session (qui glissait à chaque appel et bloquait la
 * fiche ~15 min après une simple ouverture). lock_renewed_at = dernière action
 * réelle dans le widget, null = verrou libéré. superseded_token_hash = token
 * de la personne dont la saisie vient d'être reprise, pour lui afficher
 * « Saisie reprise par … » au lieu d'une erreur générique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->timestamp('lock_renewed_at')->nullable();
            $table->string('superseded_token_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->dropIndex(['superseded_token_hash']);
            $table->dropColumn(['lock_renewed_at', 'superseded_token_hash']);
        });
    }
};
