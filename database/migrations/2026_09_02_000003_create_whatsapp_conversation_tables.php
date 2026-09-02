<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boîte de réception des autorités — ce que devient une fiche APRÈS son envoi.
 *
 * Jusqu'ici, le webhook journalisait les messages entrants puis les jetait
 * (« message entrant », type et numéro tronqué, rien d'autre). Un agent qui
 * répondait « il manque le numéro de passeport » écrivait donc dans le vide :
 * personne, côté Qayed, ne pouvait le lire. C'est la seule information de
 * retour que le canal produise, et c'était la seule qu'on ne gardait pas.
 *
 * ── Ce que ces tables ajoutent, et ce qu'elles n'ajoutent PAS ─────────────
 *
 * Les fiches restent dans `whatsapp_send_log`, avec leurs statuts de
 * livraison. Rien n'est recopié : ce serait deux vérités pour un seul envoi.
 * On ajoute seulement la COUCHE CONVERSATIONNELLE — le fil par interlocuteur,
 * et les messages qui n'ont pas d'autre foyer (entrants, et réponses libres
 * envoyées depuis l'administration).
 *
 * La chronologie affichée est donc une FUSION de deux sources, faite à la
 * lecture. C'est un peu plus de travail dans le contrôleur, et beaucoup moins
 * de risque : aucun chemin d'écriture existant n'est modifié.
 *
 * ── `whatsapp_conversations` : le fil ────────────────────────────────────
 *
 * Une ligne par NUMÉRO, pas par agent. Un numéro est ce que Meta nous donne ;
 * l'agent est une résolution locale qui peut échouer (numéro global, agent
 * supprimé, message d'un tiers). Clé sur le numéro normalisé — chiffres seuls,
 * même règle que `WhatsAppCloudChannel::formatRecipient()` — sans quoi
 * « 216 20 123 456 », « +21620123456 » et « 21620123456@c.us » ouvriraient
 * trois fils pour un seul policier.
 *
 * ── `whatsapp_conversation_messages` : les messages hors fiches ──────────
 *
 * Entrants (réponses des agents) et sortants libres (réponses de l'admin).
 * Le CONTENU est chiffré au repos : un fil d'échanges avec un poste de police
 * peut porter un nom de voyageur, un numéro de document, une consigne. Le
 * chiffrement Laravel (`encrypted` cast) rend une copie de base volée
 * illisible sans APP_KEY.
 *
 * Conséquence acceptée : on ne peut pas chercher DANS le contenu en SQL. La
 * recherche porte sur l'interlocuteur (nom, numéro, service), qui est ce qu'un
 * administrateur cherche réellement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * Numéro normalisé (chiffres seuls, international, sans « + »).
             * UNIQUE : c'est l'identité du fil. Deux lignes pour un même
             * numéro donneraient deux boîtes de réception à moitié pleines,
             * et un compteur de non-lus faux dans les deux.
             */
            $table->string('phone', 32)->unique();

            /*
             * Agent résolu par son numéro, quand il en existe un. Nullable et
             * `nullOnDelete` : un fil doit survivre à la suppression d'un
             * profil — l'échange a eu lieu, l'effacer réécrirait l'histoire.
             */
            $table->foreignId('authority_user_profile_id')->nullable()
                ->constrained('authority_user_profiles')->nullOnDelete();

            // Nom de profil WhatsApp déclaré par Meta. Indicatif : c'est
            // l'agent qui le choisit, il ne vaut pas identification.
            $table->string('contact_name')->nullable();

            /*
             * `last_inbound_at` porte la FENÊTRE DE SERVICE de 24 h : hors de
             * cette fenêtre, Meta refuse tout texte libre. C'est la seule
             * donnée qui décide si le champ « Répondre » est utilisable, donc
             * elle est une colonne et non un calcul sur la table des messages.
             */
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();

            // Tri de la liste. Couvre les fiches AUSSI, qui vivent ailleurs :
            // un fil dont la seule activité est une fiche envoyée doit
            // apparaître à sa date, pas au fond de la liste.
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_direction', 16)->nullable();
            $table->text('last_message_preview')->nullable();

            /*
             * Non-lus CÔTÉ ADMINISTRATION. Rien n'est renvoyé à Meta : poser
             * un accusé de lecture sur WhatsApp dirait à l'agent que quelqu'un
             * a ouvert son message, ce que nous ne pouvons pas garantir.
             */
            $table->unsignedInteger('unread_count')->default(0);

            $table->timestamps();

            $table->index('last_message_at');
            $table->index('authority_user_profile_id');
        });

        Schema::create('whatsapp_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();

            // inbound | outbound
            $table->string('direction', 16);

            /*
             * Identifiant Meta. Unique quand il existe — c'est lui qui rend le
             * traitement idempotent face aux rejeux du webhook. Nullable
             * parce qu'une réponse admin refusée par Meta n'en a jamais reçu :
             * la ligne existe quand même, pour que l'échec se voie à l'écran.
             */
            $table->string('wamid')->nullable();

            // text | image | document | audio | video | sticker | location
            // | contacts | button | interactive | unsupported
            $table->string('type', 32)->default('text');

            // Contenu, chiffré au repos. NULL pour les types sans texte.
            $table->text('body')->nullable();

            // Média : on garde l'identifiant Meta, JAMAIS le fichier. Il expire
            // en 30 jours côté Meta, et rapatrier des pièces jointes de police
            // sur notre stockage serait une décision de conservation à prendre
            // à part, pas un effet de bord d'une boîte de réception.
            $table->string('media_id')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_filename')->nullable();

            /*
             * Message auquel celui-ci répond (`context.id` chez Meta). C'est ce
             * qui relie « il manque le passeport » à LA fiche concernée quand
             * l'agent utilise la fonction « répondre » de WhatsApp.
             */
            $table->string('context_wamid')->nullable();

            // Auteur admin d'une réponse sortante. Une réponse à un poste de
            // police engage Qayed : elle doit être imputable à quelqu'un.
            $table->foreignUuid('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Cycle de vie Meta d'un sortant : accepted | sent | delivered
            // | read | failed. NULL pour un entrant.
            $table->string('status', 16)->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            // Instant de l'événement : réception pour un entrant, envoi pour un
            // sortant. Distinct de `created_at`, qui est l'instant où NOUS
            // l'avons appris — le webhook peut avoir plusieurs minutes de
            // retard, et la chronologie affichée doit être celle de l'agent.
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['conversation_id', 'occurred_at']);
        });

        /*
         * Unicité du wamid, en index PARTIEL : plusieurs lignes sans wamid
         * doivent rester possibles (réponses refusées par Meta), alors qu'un
         * index unique ordinaire les accepterait toutes — en SQL deux NULL ne
         * sont pas égaux — mais surtout n'apporterait rien de plus. L'index
         * partiel dit exactement la règle : un wamid connu, une seule ligne.
         */
        DB::statement(
            'CREATE UNIQUE INDEX whatsapp_conversation_messages_wamid_unique
             ON whatsapp_conversation_messages (wamid) WHERE wamid IS NOT NULL'
        );

        /*
         * Rattachement des fiches au fil.
         *
         * L'alternative — joindre sur `regexp_replace(recipient, ...)` à chaque
         * lecture — serait une comparaison non indexable sur la table la plus
         * volumineuse du produit. La colonne est remplie à l'enfilage ; les
         * lignes déjà en base sont reprises ci-dessous.
         */
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->foreignUuid('conversation_id')->nullable()
                ->constrained('whatsapp_conversations')->nullOnDelete();
            $table->index('conversation_id');
        });

        $this->backfill();
    }

    /**
     * Ouvre un fil par numéro déjà destinataire d'une fiche, et y rattache les
     * fiches existantes.
     *
     * Sans cette reprise, la boîte de réception naîtrait vide et l'historique
     * d'envoi — qui existe, et qui est exactement ce qu'un administrateur veut
     * voir en ouvrant un fil — resterait invisible jusqu'au prochain envoi.
     *
     * Tout est fait en SQL, en deux ordres : une reprise ligne à ligne sur un
     * journal d'envoi de production tiendrait des minutes.
     */
    private function backfill(): void
    {
        // `NULLIF(..., '')` : une ligne au destinataire vide ne doit pas ouvrir
        // un fil sur le numéro « rien ».
        DB::statement(
            "INSERT INTO whatsapp_conversations
                (id, phone, authority_user_profile_id, last_outbound_at, last_message_at,
                 last_message_direction, unread_count, created_at, updated_at)
             SELECT
                gen_random_uuid(),
                s.phone,
                p.id,
                s.last_sent,
                s.last_sent,
                'outbound',
                0,
                now(),
                now()
             FROM (
                SELECT
                    NULLIF(regexp_replace(recipient, '\\D', '', 'g'), '') AS phone,
                    MAX(COALESCE(sent_at, queued_at, created_at))         AS last_sent
                FROM whatsapp_send_log
                GROUP BY 1
             ) AS s
             LEFT JOIN authority_user_profiles p
                ON NULLIF(regexp_replace(COALESCE(p.whatsapp_number, ''), '\\D', '', 'g'), '') = s.phone
             WHERE s.phone IS NOT NULL
             ON CONFLICT (phone) DO NOTHING"
        );

        DB::statement(
            "UPDATE whatsapp_send_log s
             SET conversation_id = c.id
             FROM whatsapp_conversations c
             WHERE c.phone = NULLIF(regexp_replace(s.recipient, '\\D', '', 'g'), '')"
        );
    }

    public function down(): void
    {
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::dropIfExists('whatsapp_conversation_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
