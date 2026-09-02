<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des coûts Meta / WhatsApp — deux tables, deux rôles distincts.
 *
 * ── `whatsapp_billable_messages` : le REGISTRE ────────────────────────────
 *
 * Une ligne par message sortant, clé = le `wamid` rendu par Meta. Elle existe
 * dès l'envoi (donc avant tout coût) et n'est FACTURÉE que le jour où le
 * webhook remonte `delivered`.
 *
 * Sa vraie raison d'être est l'idempotence. Meta rejoue une livraison de
 * webhook jusqu'à ce qu'elle soit acquittée, et rejoue aussi après un incident
 * de son côté : sans registre, le même `delivered` incrémenterait l'agrégat
 * deux, cinq, dix fois — et un coût surcompté est pire qu'un coût absent,
 * parce qu'il a l'air juste. `counted_at` est le verrou : posé une fois, il
 * n'est jamais repris.
 *
 * Elle porte aussi l'ATTRIBUTION. Les fiches ont une ligne d'outbox qui connaît
 * l'établissement ; les codes de connexion (OTP) n'en ont aucune — ils partent
 * hors file, vers un agent, sans rattachement hôtelier. Ce registre est le seul
 * endroit où les deux flux se rejoignent avec, pour chacun, sa catégorie de
 * facturation et son établissement (nul pour l'OTP).
 *
 * Aucune donnée personnelle : ni numéro, ni contenu, ni identité de voyageur.
 * Le `wamid` est un identifiant technique opaque côté Meta.
 *
 * ── `whatsapp_message_costs` : les AGRÉGATS ───────────────────────────────
 *
 * Un jour × une catégorie × un établissement × une source. C'est ce que lisent
 * la page d'admin et la carte du dashboard : aucune vue ne balaie le registre.
 *
 * `source` porte la distinction qui compte :
 *
 *   'estimate' — calcul local, alimenté au fil de l'eau par le webhook, à
 *                partir de la grille tarifaire de config/whatsapp.php.
 *                TOUJOURS disponible.
 *   'meta'     — montants réels lus dans les analytics du WABA par
 *                `whatsapp:sync-costs`. AUTORITAIRE quand ils existent.
 *
 * Les deux cohabitent sur la même journée, volontairement : l'estimation reste
 * la seule ventilation PAR ÉTABLISSEMENT (Meta ne connaît pas nos clients), et
 * garder les deux permet de mesurer l'écart entre ce qu'on croit payer et ce
 * qu'on paie. Les lectures choisissent — elles ne mélangent jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_billable_messages', function (Blueprint $table) {
            // Le wamid EST la clé : c'est lui que Meta rejoue, c'est donc sur
            // lui que l'unicité doit être garantie par la base et non par du
            // code applicatif qui peut être contourné par une reprise SQL.
            $table->string('wamid')->primary();

            // utility | authentication | marketing | service
            $table->string('category');

            // Établissement facturé. NULL pour les codes de connexion : ils
            // appartiennent au compte autorité, pas à un client hôtelier.
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();

            // Ligne d'outbox à l'origine (fiches). NULL pour l'OTP, qui n'en a
            // pas. `nullOnDelete` : purger le journal d'envoi ne doit pas
            // effacer une ligne de comptabilité.
            $table->foreignUuid('send_log_id')->nullable()->constrained('whatsapp_send_log')->nullOnDelete();

            $table->string('template_name')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            /*
             * Verrou d'idempotence. Non nul = ce message a déjà été porté à
             * l'agrégat, quel que soit le nombre de rejeux qui suivront.
             */
            $table->timestamp('counted_at')->nullable();

            // Coût figé au moment du comptage : une révision de tarif ne
            // réécrit jamais le passé (même règle que ai_usage_events).
            $table->decimal('unit_price_usd', 12, 6)->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);

            $table->timestamps();

            $table->index(['category', 'delivered_at']);
            $table->index(['hotel_id', 'delivered_at']);
            // Balayage de la commande de resynchronisation locale.
            $table->index('counted_at');
        });

        Schema::create('whatsapp_message_costs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->date('date');
            $table->string('category');
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->nullOnDelete();

            // estimate | meta
            $table->string('source')->default('estimate');

            $table->unsignedInteger('messages')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);

            $table->timestamps();

            $table->index(['date', 'source']);
            $table->index(['hotel_id', 'date']);
        });

        /*
         * Unicité de l'agrégat.
         *
         * Un index unique ordinaire ne suffirait PAS : en SQL, deux NULL ne
         * sont pas égaux, donc PostgreSQL accepterait autant de lignes
         * « (jour, catégorie, hotel_id NULL, source) » qu'on lui en envoie —
         * et c'est précisément la forme que prennent toutes les lignes OTP et
         * toutes les lignes Meta. Sans les deux index partiels ci-dessous, la
         * ligne la plus fréquente serait la seule à ne pas être dédupliquée.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX whatsapp_message_costs_scoped_unique
                ON whatsapp_message_costs (date, category, hotel_id, source)
                WHERE hotel_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX whatsapp_message_costs_global_unique
                ON whatsapp_message_costs (date, category, source)
                WHERE hotel_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_costs');
        Schema::dropIfExists('whatsapp_billable_messages');
    }
};
