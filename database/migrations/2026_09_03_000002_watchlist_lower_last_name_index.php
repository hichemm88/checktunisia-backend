<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index fonctionnel sur `LOWER(last_name)` de la watchlist.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * Le criblage compare les noms SANS TENIR COMPTE DE LA CASSE, et le fait en
 * SQL :
 *
 *     LOWER(last_name) IN (...)                      (batchCheckGuests)
 *     LOWER(last_name) = LOWER(?)                    (checkGuest)
 *
 * Or `idx_watchlist_name_dob` porte sur `(last_name, date_of_birth)` — la
 * colonne BRUTE. Un index b-tree sur une colonne ne peut pas répondre à une
 * requête sur une FONCTION de cette colonne : PostgreSQL a indexé « MARTIN »,
 * on lui demande « martin », il ne peut rien rapprocher.
 *
 * ── Ce que ça coûtait, mesuré ───────────────────────────────────────────
 *
 * Sur 50 000 entrées synthétiques (banc local, cache chaud) :
 *
 *   recherche autorité (batchCheckGuests)   29,6 ms  →  0,38 ms   (~78x)
 *   fin de check-in     (checkGuest)         2,98 ms →  0,79 ms   (~3,8x)
 *
 * La première passait en Seq Scan : 50 000 lignes lues, 49 994 rejetées, à
 * CHAQUE recherche du portail autorité. Le coût croît linéairement avec la
 * taille de la watchlist — c'est-à-dire qu'il empire précisément à mesure que
 * le dispositif est réellement utilisé.
 *
 * ── Le détail non évident ───────────────────────────────────────────────
 *
 * Les deux critères sont reliés par un OR (numéro de document OU nom). Pour un
 * OR, PostgreSQL ne sait combiner des index que si les DEUX côtés sont
 * indexables — sinon il abandonne les index et balaie la table. Le côté
 * document avait pourtant bien son index (`idx_watchlist_doc`) : il ne servait
 * à rien tant que l'autre côté manquait.
 *
 * Cet index ne rend donc pas seulement le nom cherchable ; il RÉACTIVE un index
 * existant, et le plan devient un BitmapOr des deux.
 *
 * ── Pourquoi non partiel ────────────────────────────────────────────────
 *
 * Un index restreint à `status = 'active'` serait plus compact et resterait
 * utilisable ici. On s'en abstient : il deviendrait inutilisable pour toute
 * requête future qui ne filtre pas sur ce statut (une console d'administration
 * qui liste les fiches expirées, par exemple), pour une économie d'espace sans
 * portée sur une table de cette taille.
 *
 * CONCURRENTLY, comme pour les index trigram : la création prend sinon un
 * verrou d'écriture, et un hôtel qui enregistre un voyageur pendant la
 * migration serait bloqué. PostgreSQL refuse CONCURRENTLY dans une
 * transaction, d'où `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_watchlist_lower_last_name
             ON watchlist_entries (LOWER(last_name))'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_watchlist_lower_last_name');
    }
};
