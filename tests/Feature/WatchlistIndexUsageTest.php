<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le criblage de la watchlist doit pouvoir s'appuyer sur un index.
 *
 * ── Pourquoi ce test regarde le PLAN et pas un chronomètre ──────────────
 *
 * Une durée dépend de la machine et du cache : elle rendrait la suite
 * instable sans rien garantir. Le plan d'exécution, lui, dit la seule chose
 * qui compte — PostgreSQL sait-il, oui ou non, se servir d'un index pour
 * cette requête.
 *
 * ── Pourquoi `enable_seqscan = off` ─────────────────────────────────────
 *
 * Sur les quelques lignes d'une base de test, PostgreSQL choisit un Seq Scan
 * même quand un index parfait existe : parcourir dix lignes est plus rapide
 * que lire un index. Un test qui exigerait « pas de Seq Scan » serait donc
 * rouge en permanence, pour une raison qui n'est pas un défaut.
 *
 * En désactivant le Seq Scan, on pose la question utile : SI le planificateur
 * voulait un index, en aurait-il un ? Sans `idx_watchlist_lower_last_name`,
 * la réponse est non — PostgreSQL retombe sur le balayage complet, faute de
 * pouvoir rapprocher `LOWER(last_name)` d'un index posé sur `last_name`.
 *
 * Ce réglage est local à la transaction du test et ne quitte pas celle-ci.
 */
class WatchlistIndexUsageTest extends TestCase
{
    use RefreshDatabase;

    /*
     * Le OR de criblage, isolé de ses conditions d'accompagnement.
     *
     * Les filtres `status` / `expires_at` sont volontairement OMIS. En les
     * gardant, PostgreSQL peut satisfaire « pas de Seq Scan » par
     * `idx_watchlist_status` seul et appliquer le OR en simple filtre — le test
     * passerait alors au vert sans que le nom soit indexé, c'est-à-dire en
     * ratant exactement ce qu'il surveille.
     *
     * Réduit au OR, le plan n'a plus d'échappatoire : soit les deux côtés sont
     * indexables, soit c'est un balayage complet.
     */
    private const SCREENING_OR = <<<'SQL'
        SELECT * FROM watchlist_entries
        WHERE document_number IN ('DOC-SYNTH-1', 'DOC-SYNTH-2')
           OR LOWER(last_name) IN ('nomsynth1', 'nomsynth2')
    SQL;

    private function plan(string $sql): string
    {
        DB::statement('SET LOCAL enable_seqscan = off');

        return collect(DB::select('EXPLAIN '.$sql))
            ->map(fn ($row) => (array) $row)
            ->map(fn (array $row) => (string) reset($row))
            ->implode("\n");
    }

    public function test_the_screening_query_can_use_an_index_on_the_lowercased_name(): void
    {
        $plan = $this->plan(self::SCREENING_OR);

        $this->assertStringContainsString(
            'idx_watchlist_lower_last_name',
            $plan,
            "Le criblage retombe sur un balayage complet de la watchlist.\n"
            ."`LOWER(last_name)` ne peut pas se servir d'un index posé sur `last_name` :\n"
            ."il faut un index FONCTIONNEL. Cette requête s'exécute à chaque recherche\n"
            ."du portail autorité et à chaque fin de check-in.\n\nPlan obtenu :\n".$plan,
        );
    }

    public function test_the_document_number_side_of_the_or_is_indexed_too(): void
    {
        /*
         * Détail non évident : pour un OR, PostgreSQL ne combine des index que
         * si les DEUX côtés sont indexables. Le côté document avait bien son
         * index, mais il restait inutilisé tant que le côté nom manquait.
         *
         * Vérifier les deux ensemble empêche qu'on retire un jour l'un des deux
         * en croyant l'autre autonome.
         */
        $plan = $this->plan(self::SCREENING_OR);

        $this->assertStringContainsString('idx_watchlist_doc', $plan, "Plan obtenu :\n".$plan);
        $this->assertStringContainsString('BitmapOr', $plan, "Plan obtenu :\n".$plan);
    }
}
