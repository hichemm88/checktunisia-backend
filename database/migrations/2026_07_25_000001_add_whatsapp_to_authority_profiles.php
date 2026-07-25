<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envoi direct des fiches de police aux agents (remplace à terme le relais
 * mono-destinataire « on s'envoie à nous-mêmes »). On dote chaque agent
 * (authority_user_profiles) d'un numéro WhatsApp vérifié par l'admin et d'un
 * interrupteur « reçoit les fiches ». Le numéro n'est JAMAIS saisi par un
 * établissement — seul l'admin le renseigne (les fiches contiennent des
 * passeports : pas d'exfiltration possible vers un numéro libre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('authority_user_profiles', function (Blueprint $table) {
            // Numéro international normalisé (chiffres, ex. 21620123456). Le JID
            // WhatsApp (…@c.us) est dérivé à l'envoi.
            $table->string('whatsapp_number', 20)->nullable()->after('rank');
            // L'agent reçoit-il les fiches par WhatsApp ? (peut exister dans le
            // système pour la recherche/login sans recevoir d'envoi).
            $table->boolean('receives_whatsapp_fiches')->default(false)->after('whatsapp_number');
        });
    }

    public function down(): void
    {
        Schema::table('authority_user_profiles', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'receives_whatsapp_fiches']);
        });
    }
};
