<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réactions WhatsApp (emoji sur un message ou une fiche).
 *
 * Le webhook Cloud API envoie une réaction comme un message à part
 * (`type: "reaction"`) qui porte `reaction.message_id` (le wamid VISÉ, pas
 * celui de la réaction elle-même) et `reaction.emoji` (vide/absent = retrait).
 * Ni l'un ni l'autre n'avait de colonne : la réaction s'enregistrait quand
 * même — `type` accepte n'importe quelle valeur — mais sans cible ni emoji,
 * ce qui produisait l'aperçu brut « [reaction] ».
 *
 * `context_wamid` existe déjà mais sert à autre chose (le "répondre à" de
 * WhatsApp, alimenté par `context.id`) : le détourner casserait cette
 * fonctionnalité pour gagner une colonne. On en ajoute deux, dédiées.
 *
 * Chaque événement reste une LIGNE, jamais une mise à jour destructive :
 * Meta ne redonne pas l'historique des réactions, seulement l'état courant à
 * chaque webhook. La ligne la plus récente par `target_wamid` EST l'état
 * courant — une réaction qui remplace la précédente, ou qui la retire quand
 * `reaction_emoji` est NULL. Le contrôleur qui construit le fil résout ça à la
 * lecture, comme il le fait déjà pour fusionner fiches et messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table) {
            // Wamid du message VISÉ par la réaction (`reaction.message_id`
            // chez Meta). Peut désigner une ligne de cette table ou une fiche
            // de `whatsapp_send_log` (`message_id_whatsapp`) — les deux
            // partagent le même espace d'identifiants Meta.
            $table->string('target_wamid')->nullable()->after('context_wamid');

            // Emoji de la réaction. NULL = cette ligne est un RETRAIT, pas une
            // absence de donnée : Meta envoie explicitement un événement
            // `reaction` sans emoji quand l'agent retire la sienne.
            $table->string('reaction_emoji', 16)->nullable()->after('target_wamid');

            $table->index('target_wamid');
        });

        /*
         * Reprise des lignes déjà en base. Aucun webhook brut n'a été
         * conservé (le contrôleur ne loggait que type + wamid, jamais le
         * payload) : impossible de retrouver la cible ou l'emoji d'une
         * réaction déjà enregistrée. On corrige seulement l'aperçu de fil,
         * qui affichait le crochet brut du type — remplacé par un texte
         * neutre plutôt que laissé tel quel.
         */
        DB::table('whatsapp_conversations')
            ->where('last_message_preview', '[reaction]')
            ->update(['last_message_preview' => 'Réaction reçue']);
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['target_wamid']);
            $table->dropColumn(['target_wamid', 'reaction_emoji']);
        });
    }
};
