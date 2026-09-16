<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotence de l'API partenaire (§2) : deux sessions du même
 * (établissement, booking_ref) ne doivent jamais produire deux fiches.
 *
 * Index UNIQUE PARTIEL, scopé aux fiches créées via l'API (metadata->>'source'
 * = 'api') : le flux natif n'impose aujourd'hui aucune unicité sur
 * booking_reference (texte libre, ressaisie possible), et cette migration ne
 * doit rien lui retirer.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_check_ins_api_booking_ref_unique
            ON check_ins (hotel_id, booking_reference)
            WHERE booking_reference IS NOT NULL
              AND deleted_at IS NULL
              AND metadata->>'source' = 'api'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_check_ins_api_booking_ref_unique');
    }
};
