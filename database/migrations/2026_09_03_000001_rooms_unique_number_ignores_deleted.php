<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le numéro d'une chambre supprimée doit redevenir libre.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * `Room` est en suppression LOGIQUE : la ligne reste, seul `deleted_at` est
 * posé. Or `rooms_hotel_id_number_unique` portait sur `(hotel_id, number)`
 * sans exclure les lignes supprimées. Un numéro restait donc pris pour
 * toujours.
 *
 * La conséquence n'était pas un simple refus. Le contrôleur teste d'abord
 * l'existence en PHP (`Room::where(...)`), et ce test-là applique le filtre
 * `deleted_at IS NULL` d'Eloquent : il ne voyait pas la chambre supprimée et
 * laissait passer. L'INSERT butait ensuite sur l'index, et l'écran recevait
 * une **erreur 500** — sur un geste de réception ordinaire : supprimer la 204,
 * puis la recréer.
 *
 * Deux règles pour une même contrainte, l'une en PHP et l'autre en SQL, qui ne
 * s'accordaient pas sur ce qu'est une chambre existante.
 *
 * ── Pourquoi un index PARTIEL ───────────────────────────────────────────
 *
 * C'est déjà le motif retenu ailleurs dans ce schéma :
 *
 *     users_one_owner_per_org ... WHERE role_org = 'owner' AND deleted_at IS NULL
 *
 * Quelqu'un connaissait donc le piège. On l'applique ici, ce qui aligne la
 * garantie SQL sur ce que le code croyait déjà vérifier.
 *
 * ── Sûreté sur des données existantes ───────────────────────────────────
 *
 * Le nouvel index est strictement PLUS PERMISSIF que l'ancien : tout jeu de
 * données qui satisfaisait `(hotel_id, number)` unique sur TOUTES les lignes
 * satisfait a fortiori la même unicité restreinte aux lignes vivantes. La
 * création ne peut donc pas échouer sur une base en production.
 *
 * L'ordre compte : on crée le nouvel index AVANT de retirer l'ancien, pour
 * qu'aucune fenêtre ne laisse passer un doublon entre les deux.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX rooms_hotel_id_number_active_unique
             ON rooms (hotel_id, number) WHERE deleted_at IS NULL'
        );

        /*
         * `DROP CONSTRAINT` et non `DROP INDEX` : l'unicite d'origine a ete
         * posee par `$table->unique(...)`, donc sous forme de CONTRAINTE. Son
         * index n'est qu'un support, et PostgreSQL refuse de le supprimer tant
         * que la contrainte s'appuie dessus.
         */
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT IF EXISTS rooms_hotel_id_number_unique');
    }

    public function down(): void
    {
        /*
         * Le retour arrière peut légitimement ÉCHOUER : si un numéro a été
         * réutilisé depuis (ce que cette migration autorise précisément), il
         * existe alors deux lignes partageant `(hotel_id, number)`, dont une
         * supprimée — et l'ancien index global les refuse.
         *
         * On ne « répare » pas en supprimant des chambres pour faire passer un
         * rollback : perdre des données pour revenir en arrière serait pire que
         * le problème d'origine. L'échec est le bon comportement, et il dit
         * exactement ce qui s'est passé.
         */
        DB::statement(
            'ALTER TABLE rooms ADD CONSTRAINT rooms_hotel_id_number_unique UNIQUE (hotel_id, number)'
        );

        DB::statement('DROP INDEX IF EXISTS rooms_hotel_id_number_active_unique');
    }
};
