<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amorce du registre de consommation à partir des fiches DÉJÀ finalisées.
 *
 * Sans cette reprise, le compteur du mois en cours retomberait à zéro le
 * jour du déploiement et l'historique de dépassement deviendrait illisible.
 *
 * Critère de reprise : `completed_at IS NOT NULL` — l'horodatage posé par
 * `CheckInService::complete()`, donc exactement les fiches qui ont été
 * déclarées. Les brouillons jamais finalisés sont volontairement EXCLUS
 * (ils ne consommaient rien en réalité ; l'ancien COUNT les prenait à tort).
 * Les fiches supprimées (soft delete) sont exclues.
 *
 * Une fiche annulée APRÈS avoir été déclarée est reprise avec `cancelled_at`
 * horodaté : la consommation reste due, l'annulation reste lisible.
 *
 * NON DESTRUCTIVE et RÉEXÉCUTABLE : aucune donnée existante n'est modifiée
 * ni supprimée, et l'insertion passe par ON CONFLICT DO NOTHING (unicité sur
 * check_in_id) — relancer la migration ne crée aucun doublon.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `updated_at` de l'annulation : on prend le champ le plus fiable
        // disponible sur la fiche, sans jamais inventer de date antérieure à
        // la déclaration.
        DB::statement(<<<'SQL'
            INSERT INTO checkin_usage_events
                (check_in_id, organization_id, hotel_id, subscription_id,
                 period, consumed_at, cancelled_at, created_at, updated_at)
            SELECT
                c.id,
                h.organization_id,
                c.hotel_id,
                (
                    SELECT s.id FROM subscriptions s
                    WHERE s.organization_id = h.organization_id
                      AND s.status IN ('active', 'trial')
                    ORDER BY s.started_at DESC
                    LIMIT 1
                ),
                date_trunc('month', c.completed_at)::date,
                c.completed_at,
                CASE WHEN c.status = 'cancelled'
                     THEN GREATEST(c.updated_at, c.completed_at)
                     ELSE NULL END,
                now(),
                now()
            FROM check_ins c
            JOIN hotels h ON h.id = c.hotel_id
            WHERE c.completed_at IS NOT NULL
              AND c.deleted_at IS NULL
              AND h.organization_id IS NOT NULL
            ON CONFLICT (check_in_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // Le registre est en ajout seul : on ne retire que ce que cette
        // reprise a pu poser, c'est-à-dire l'intégralité des lignes — la
        // table elle-même est supprimée par la migration de structure.
        DB::table('checkin_usage_events')->truncate();
    }
};
