<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crée le schéma Postgres qui isole le mini-CRM de prospection commerciale
 * (voir config/database.php, connexion 'prospection') des données de
 * production Qayed (fiches voyageurs, établissements clients).
 *
 * Raw SQL et non Schema::create() : tant que ce schéma n'existe pas, le
 * search_path de la connexion 'prospection' pointe vers une cible absente et
 * Laravel ne pourrait résoudre aucune table non qualifiée. CREATE SCHEMA, lui,
 * ne dépend pas du search_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = (string) config('database.connections.prospection.search_path', 'prospection');

        DB::connection('prospection')->statement(
            'CREATE SCHEMA IF NOT EXISTS "'.str_replace('"', '""', $schema).'"'
        );
    }

    public function down(): void
    {
        $schema = (string) config('database.connections.prospection.search_path', 'prospection');

        // CASCADE : ce schéma n'appartient qu'au module prospection, aucune
        // donnée de production ne peut s'y trouver.
        DB::connection('prospection')->statement(
            'DROP SCHEMA IF EXISTS "'.str_replace('"', '""', $schema).'" CASCADE'
        );
    }
};
