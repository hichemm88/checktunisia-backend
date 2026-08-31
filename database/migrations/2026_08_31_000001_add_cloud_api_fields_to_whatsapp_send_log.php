<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration vers la WhatsApp Cloud API officielle.
 *
 * Trois manques du journal d'envoi apparaissent avec l'API officielle :
 *
 *  1. Le MODÈLE. Hors fenêtre de 24 h, Meta n'accepte que des modèles
 *     approuvés à variables. La légende texte (`caption`) ne suffit plus : il
 *     faut mémoriser quel modèle et quelles variables ont été soumis, sinon un
 *     renvoi ne peut pas reproduire l'envoi d'origine.
 *
 *  2. La LIVRAISON, distincte de l'ENVOI. Avec le relais Web, « envoyé »
 *     valait acquittement. Avec la Cloud API, un 200 signifie seulement que
 *     Meta a accepté le message : la suite (delivered, read, failed) arrive
 *     plus tard par webhook, et un échec de livraison porte son propre code.
 *     Confondre les deux ferait passer pour transmise une fiche que le
 *     destinataire n'a jamais reçue — sur un canal qui porte une obligation
 *     légale, c'est le pire des mensonges possibles.
 *
 *  3. La CORRÉLATION. Le webhook ne connaît que le `wamid`. Sans index sur
 *     `message_id_whatsapp`, chaque accusé de réception scannerait tout le
 *     journal.
 *
 * Le canal est journalisé par ligne (et non déduit de la configuration
 * courante) : après bascule, il faut pouvoir dire par quel canal une fiche
 * donnée est réellement partie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            // Modèle soumis à Meta et ses variables (header / body / button).
            $table->string('template_name')->nullable();
            $table->string('template_language', 12)->nullable();
            $table->json('template_params')->nullable();

            // Canal ayant réellement transmis cette ligne (« whatsapp_web »,
            // « whatsapp_cloud »).
            $table->string('channel', 24)->nullable();

            // Cycle de vie côté Meta, alimenté par le webhook.
            // accepted -> sent -> delivered -> read, ou failed.
            $table->string('delivery_status', 16)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Code d'erreur natif (Meta) du dernier échec, à l'envoi comme à la
            // livraison. Le libellé se reformule, le code se recoupe.
            $table->string('error_code', 24)->nullable();
        });

        // Corrélation webhook → ligne de journal. Non unique : un renvoi
        // réutilise la ligne, et deux lignes pourraient théoriquement porter le
        // même identifiant si Meta rejouait une réponse.
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->index(['message_id_whatsapp'], 'idx_wa_log_wamid');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->dropIndex('idx_wa_log_wamid');
            $table->dropColumn([
                'template_name',
                'template_language',
                'template_params',
                'channel',
                'delivery_status',
                'delivered_at',
                'read_at',
                'error_code',
            ]);
        });
    }
};
