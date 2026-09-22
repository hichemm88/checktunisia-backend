<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colonnes anti-doublon des 3 déclencheurs de notification (§ Notifications
 * push) : sans elles, une commande planifiée relancée (ou tournant plus
 * souvent que son intervalle logique) renverrait le même digest ou le même
 * rappel de démo plusieurs fois.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Date (pas timestamp) : un digest par jour civil Africa/Tunis, jamais deux.
            $table->date('last_digest_sent_at')->nullable()->after('notif_activity_enabled');
        });

        Schema::table('establishments', function (Blueprint $table) {
            $table->timestamp('demo_reminder_sent_at')->nullable()->after('next_action_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_digest_sent_at');
        });

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('demo_reminder_sent_at');
        });
    }
};
