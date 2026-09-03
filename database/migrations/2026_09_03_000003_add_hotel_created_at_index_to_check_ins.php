<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index composite `(hotel_id, created_at)` sur `check_ins`.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * `DashboardController::index()` compte les check-ins du mois avec
 * `whereMonth('created_at', ...)->whereYear('created_at', ...)`. Ce sont des
 * fonctions appliquées à la colonne : aucun index b-tree ordinaire sur
 * `created_at` ne peut y répondre par une recherche — PostgreSQL doit
 * examiner CHAQUE check-in jamais enregistré par l'établissement pour ne
 * garder que ceux du mois courant.
 *
 * `idx_check_ins_hotel` filtre bien sur `hotel_id`, donc la lecture reste
 * cantonnée aux lignes de l'établissement — mais pas au mois. Le coût de ce
 * comptage grandit avec le nombre TOTAL de check-ins jamais faits par
 * l'établissement, pas avec les ~30 lignes du mois affichées à l'écran :
 * exactement le défaut déjà corrigé pour `buildOccupancy()` plus haut dans ce
 * même fichier (requête bornée d'un seul côté → coût proportionnel à tout
 * l'historique). Un établissement multi-propriétés avec plusieurs années
 * d'activité est le premier à le sentir, puisque c'est lui qui a le plus de
 * lignes accumulées.
 *
 * ── Le correctif ──────────────────────────────────────────────────────────
 *
 * Le contrôleur passe à `whereBetween('created_at', [debut_mois, fin_mois])`
 * (commit applicatif séparé) : une comparaison directe sur la colonne, que
 * cet index peut satisfaire par une recherche bornée au lieu d'un parcours
 * complet. Même bénéfice pour `HotelAdminController::metrics()` côté
 * back-office, qui répète le même calcul par établissement (`withCount`).
 *
 * CONCURRENTLY : `check_ins` reçoit des écritures en continu (chaque
 * check-in), un `CREATE INDEX` ordinaire poserait un verrou d'écriture le
 * temps de la construction. PostgreSQL refuse CONCURRENTLY dans une
 * transaction, d'où `$withinTransaction = false`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_check_ins_hotel_created
             ON check_ins (hotel_id, created_at)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_check_ins_hotel_created');
    }
};
