<?php

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Retire les donnees de demonstration (DemoDataSeeder) qui polluaient les
 * chiffres du tableau de bord — notamment un abonnement actif fictif sur
 * « Hotel Sousse Azur » qui gonflait le MRR alors qu'il n'y a qu'un vrai client.
 *
 * Suppression chirurgicale : uniquement les enregistrements aux identifiants
 * exacts du seeder de demo (slug, e-mails, code). Aucun autre client n'est
 * touche. Le seeder est par ailleurs retire du cycle de deploiement (Dockerfile
 * + DatabaseSeeder) pour qu'il ne reinjecte plus rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Etablissement de demo (withTrashed : meme s'il a ete masque depuis
        //    l'admin, son abonnement peut rester actif et gonfler le MRR).
        $hotel = Hotel::withTrashed()->where('slug', 'hotel-sousse-azur')->first();
        if ($hotel) {
            // L'abonnement fictif est le vrai coupable du revenu affiche.
            // (aucune facture/paiement de demo, mais on nettoie par securite avant
            //  de forcer la suppression de l'etablissement.)
            $subIds = Subscription::where('hotel_id', $hotel->id)->pluck('id');
            Payment::whereIn('hotel_id', [$hotel->id])->delete();
            Invoice::whereIn('subscription_id', $subIds)->orWhere('hotel_id', $hotel->id)->delete();
            Subscription::whereIn('id', $subIds)->delete(); // cascade -> subscription_events

            // forceDelete : suppression reelle (cascade rooms / adresses / contacts /
            // parametres / user_hotels / notifications / ai_usage / whatsapp).
            $hotel->forceDelete();
        }

        // 2. Comptes de demonstration (soft delete : reversible, sortis des
        //    listes et compteurs). Le vrai admin plateforme (admin@qayed.tn)
        //    n'est pas concerne.
        User::whereIn('email', [
            'hotelier@hotel-azur.tn',
            'reception@hotel-azur.tn',
            'agent@police.tn',
        ])->delete();

        // 3. Organisation d'autorite de demo + son profil agent.
        $org = AuthorityOrganization::where('code', 'DGSN')->first();
        if ($org) {
            AuthorityUserProfile::where('organization_id', $org->id)->delete();
            $org->delete();
        }
    }

    public function down(): void
    {
        // Donnees de demo : pas de restauration (elles seront regenerees par le
        // seeder si on le relance manuellement en local).
    }
};
