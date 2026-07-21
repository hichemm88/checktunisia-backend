<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Neutralise les donnees de demonstration (DemoDataSeeder) qui gonflaient le
 * tableau de bord — notamment un abonnement actif fictif sur « Hotel Sousse
 * Azur » compte dans le MRR alors qu'il n'y a qu'un vrai client.
 *
 * IMPORTANT : cette migration n'utilise que des UPDATE surs et idempotents
 * (annulation d'abonnement + soft-delete), JAMAIS de suppression physique. En
 * production, l'etablissement de demo peut porter des enregistrements lies
 * (check-ins, factures...) dont les cles etrangeres feraient echouer un delete —
 * ce qui casserait le deploiement (la commande `migrate` est chainee en `&&`
 * dans le Dockerfile, sans `|| true`). Des UPDATE ne peuvent pas violer de FK.
 *
 * Ciblage strict par slug / e-mails / code du seeder de demo : aucun autre
 * client n'est touche. Reversible (deleted_at / is_active).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Abonnement(s) de l'etablissement de demo -> annule(s). withTrashed
        //    implicite : on cible par hotel_id, meme si l'hotel est deja masque.
        //    Sortis du MRR (le dashboard ne compte que status = 'active'). Le
        //    churn n'est pas affecte : ces abonnements ont organization_id NULL,
        //    exclus du calcul d'attrition.
        $demoHotelIds = DB::table('hotels')->where('slug', 'hotel-sousse-azur')->pluck('id');
        if ($demoHotelIds->isNotEmpty()) {
            DB::table('subscriptions')
                ->whereIn('hotel_id', $demoHotelIds)
                ->where('status', 'active')
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

            // 2. Etablissement de demo -> soft-delete (sort des compteurs/listes).
            DB::table('hotels')
                ->whereIn('id', $demoHotelIds)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }

        // 3. Comptes de demonstration -> soft-delete (le vrai admin plateforme
        //    admin@qayed.tn n'est pas concerne).
        DB::table('users')
            ->whereIn('email', ['hotelier@hotel-azur.tn', 'reception@hotel-azur.tn', 'agent@police.tn'])
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        // 4. Organisation d'autorite de demo -> desactivee (pas de suppression :
        //    des profils/journaux peuvent y referer).
        DB::table('authority_organizations')
            ->where('code', 'DGSN')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Donnees de demo : pas de restauration automatique.
    }
};
