<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le suivi côté AUTORITÉ sur les correspondances liste de surveillance.
 *
 * Le même événement (WatchlistHit) alimente déjà l'alerte hôtel
 * (notified_hotel_at / acknowledged_at). On y greffe un cycle de vie propre à
 * l'autorité — Nouvelle → Vue → Prise en charge — sans toucher aux colonnes
 * existantes ni aux données déjà enregistrées.
 *
 * Strictement additif et réversible : les nouvelles colonnes prennent une
 * valeur par défaut ('new' / null) pour toutes les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlist_hits', function (Blueprint $table) {
            $table->string('authority_status', 20)->default('new')->after('acknowledged_by'); // new|seen|acknowledged
            $table->timestamp('authority_seen_at')->nullable()->after('authority_status');
            $table->foreignUuid('authority_seen_by')->nullable()->after('authority_seen_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('authority_acknowledged_at')->nullable()->after('authority_seen_by');
            $table->foreignUuid('authority_acknowledged_by')->nullable()->after('authority_acknowledged_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['authority_status'], 'watchlist_hits_authority_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('watchlist_hits', function (Blueprint $table) {
            $table->dropIndex('watchlist_hits_authority_status_index');
            $table->dropConstrainedForeignId('authority_seen_by');
            $table->dropConstrainedForeignId('authority_acknowledged_by');
            $table->dropColumn([
                'authority_status',
                'authority_seen_at',
                'authority_acknowledged_at',
            ]);
        });
    }
};
