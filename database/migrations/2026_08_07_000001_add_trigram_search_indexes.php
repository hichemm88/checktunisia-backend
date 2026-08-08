<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index trigram pour les recherches par nom et par numéro de document.
 *
 * Toutes les recherches du produit — y compris la recherche NATIONALE du portail
 * autorité, qui balaie `guests` sans cloisonnement tenant — utilisent
 * `ILIKE '%…%'`. Un joker en tête rend inutilisable tout index b-tree : la table
 * n'avait donc AUCUN index exploitable sur les noms, et `idx_travel_docs_number`
 * (b-tree) ne servait jamais pour ces requêtes.
 *
 * Mesure sur 200 000 voyageurs (banc local, cache chaud — l'écart est plus grand
 * en production avec un cache froid) :
 *
 *   recherche par nom      106,5 ms  →  13,1 ms   (~8x)
 *   recherche par document   98,6 ms →   0,5 ms   (~180x)
 *
 * Les deux passent d'un Seq Scan à un Bitmap Index Scan.
 *
 * CONCURRENTLY : la création d'un index GIN sur une grosse table verrouille les
 * écritures pendant plusieurs secondes. En production, un hôtel qui enregistre
 * un voyageur pendant la migration se verrait bloqué. `CONCURRENTLY` ne prend
 * pas ce verrou, au prix d'un balayage supplémentaire de la table.
 *
 * D'où `$withinTransaction = false` : PostgreSQL refuse `CREATE INDEX
 * CONCURRENTLY` à l'intérieur d'un bloc transactionnel.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // pg_trgm fournit l'opérateur gin_trgm_ops. Disponible en standard sur
        // les Postgres managés (Railway inclus).
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Consommé par AuthoritySearchController::search() et
        // CheckInController::index() (recherche par nom côté hôtel).
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_guests_first_name_trgm ON guests USING gin (first_name gin_trgm_ops)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_guests_last_name_trgm ON guests USING gin (last_name gin_trgm_ops)');

        // Consommé par la recherche par numéro de document du portail autorité.
        // On conserve le b-tree existant : il reste utile pour les égalités
        // strictes et les tris.
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_travel_docs_number_trgm ON travel_documents USING gin (document_number gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_guests_first_name_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_guests_last_name_trgm');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_travel_docs_number_trgm');

        // L'extension n'est pas supprimée : d'autres objets peuvent en dépendre.
    }
};
