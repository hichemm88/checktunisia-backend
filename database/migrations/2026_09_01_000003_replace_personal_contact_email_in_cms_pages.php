<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'adresse de contact publiée sur le site devient celle de la société.
 *
 * Les CGV et la politique de confidentialité désignaient une adresse Gmail
 * personnelle comme point de contact pour l'exercice des droits, les
 * réclamations et les demandes de remboursement — sur des pages que lit un
 * examinateur (organisme de paiement, INPDP) et n'importe quel client. Le
 * seeder est corrigé, mais il ne réécrit jamais une page existante : sans
 * cette migration, la production garderait l'ancienne adresse.
 *
 * Le remplacement porte sur le JSON brut des colonnes `content` et `meta`,
 * donc sur tous les blocs Puck sans dépendre de leur forme, et sur toutes les
 * pages — l'adresse a pu être collée ailleurs que dans les pages légales.
 */
return new class extends Migration
{
    private const PERSONAL = 'hichemmathlouthi@gmail.com';
    private const COMPANY = 'contact@qayed.tn';

    public function up(): void
    {
        $pages = DB::table('pages')->select('id', 'content', 'meta')->get();

        foreach ($pages as $page) {
            $update = [];

            foreach (['content', 'meta'] as $column) {
                $json = $page->{$column};

                // Colonne vide (page jamais éditée) ou sans l'adresse : rien à faire.
                if (! is_string($json) || ! str_contains($json, self::PERSONAL)) {
                    continue;
                }

                $update[$column] = str_replace(self::PERSONAL, self::COMPANY, $json);
            }

            if ($update !== []) {
                $update['updated_at'] = now();
                DB::table('pages')->where('id', $page->id)->update($update);
            }
        }
    }

    /**
     * Volontairement sans effet.
     *
     * L'inverse remettrait une adresse personnelle sur des pages publiques —
     * y compris là où « contact@qayed.tn » a toujours été le texte voulu, que
     * rien ne distingue après coup de ce que cette migration a écrit. Un
     * rollback ne doit pas republier une donnée personnelle.
     */
    public function down(): void
    {
        //
    }
};
