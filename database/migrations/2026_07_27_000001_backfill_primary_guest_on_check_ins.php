<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage de données : certaines fiches ont des voyageurs mais AUCUN marqué
 * `is_primary` (ancien flux d'ajout qui pouvait rétrograder l'unique principal,
 * ou suppression du principal sans promotion). Résultat : « Sans nom » à
 * l'Accueil, ordre incertain dans les exports/fiches de police.
 *
 * Pour chaque fiche concernée, on marque le voyageur le plus anciennement
 * ajouté comme principal. Idempotent : ré-exécuté, il ne trouve plus de fiche
 * sans principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE check_in_guests
            SET is_primary = true
            WHERE id IN (
                SELECT DISTINCT ON (check_in_id) id
                FROM check_in_guests
                WHERE check_in_id IN (
                    SELECT check_in_id
                    FROM check_in_guests
                    GROUP BY check_in_id
                    HAVING bool_or(is_primary) = false
                )
                ORDER BY check_in_id, added_at ASC, id ASC
            )
        SQL);
    }

    public function down(): void
    {
        // Rattrapage de données non réversible (on ne sait pas distinguer les
        // principaux backfillés des principaux d'origine). No-op volontaire.
    }
};
