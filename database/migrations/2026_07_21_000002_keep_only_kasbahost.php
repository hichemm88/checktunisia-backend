<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remet le tableau de bord au reel : un seul vrai client (KASBAHOST). Toutes les
 * autres organisations sont des donnees de test qui polluaient le MRR et
 * l'attrition (« 1 parti ce mois » = un abonnement de test resilie/expire).
 *
 * Sur, reversible, increvable : UNIQUEMENT des UPDATE (aucune suppression
 * physique -> aucune contrainte FK ne peut faire echouer le deploiement).
 *
 * - Abonnements des orgs NON conservees (et abonnements legacy sans org) ->
 *   'cancelled' avec cancelled_at LAISSE NULL : exclus a la fois du MRR
 *   (status != active) ET de l'attrition (le churn exige cancelled_at dans le
 *   mois). Resultat : churn = 0, MRR = le seul abonnement de Kasbahost.
 * - Etablissements et organisations de test -> soft-delete (sortis des
 *   compteurs, listes, cohortes d'activation et signups recents).
 *
 * GARDE-FOU : si aucune organisation « Kasbahost » n'est trouvee, la migration
 * ne touche a rien (mieux vaut ne rien faire que risquer de masquer le vrai
 * client sur une correspondance ratee).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Organisation(s) reelle(s) a conserver : Kasbahost (nom confirme).
        // ILIKE 'kasbahost%' : insensible a la casse, tolere un suffixe (SARL...).
        $keepOrgIds = DB::table('organizations')
            ->whereRaw('name ILIKE ?', ['kasbahost%'])
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($keepOrgIds->isEmpty()) {
            return; // garde-fou : Kasbahost introuvable -> on ne neutralise rien.
        }

        $notKept = function ($q) use ($keepOrgIds) {
            $q->whereNull('organization_id')                 // abonnements/etabs legacy (demo/test)
              ->orWhereNotIn('organization_id', $keepOrgIds); // tout sauf Kasbahost
        };

        // 1. Abonnements de test -> annules, cancelled_at NULL (hors MRR + hors churn).
        DB::table('subscriptions')
            ->where($notKept)
            ->update(['status' => 'cancelled', 'cancelled_at' => null, 'updated_at' => now()]);

        // 2. Etablissements de test -> masques.
        DB::table('hotels')
            ->where($notKept)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        // 3. Organisations de test -> masquees (Kasbahost conserve).
        DB::table('organizations')
            ->whereNotIn('id', $keepOrgIds)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Donnees de test : pas de restauration automatique.
    }
};
