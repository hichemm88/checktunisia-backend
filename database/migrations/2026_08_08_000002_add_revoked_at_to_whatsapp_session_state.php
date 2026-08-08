<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rend la révocation de la session WhatsApp DURABLE.
 *
 * Jusqu'ici l'état ne connaissait que « disconnected » : au redémarrage suivant
 * le worker repassait en « initializing » et l'information se perdait. Un
 * ré-appairage réellement obligatoire devenait alors indiscernable d'un simple
 * démarrage en cours — le relais restait muet pendant que les fiches
 * s'accumulaient.
 *
 * `revoked_at` retient l'instant où WhatsApp a invalidé l'appareil lié. Le
 * worker s'en sert pour savoir qu'une archive antérieure ne vaut plus rien, et
 * cesser de la restaurer en boucle.
 *
 * Purement additif : une colonne nullable, aucune donnée touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('last_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_session_state', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
