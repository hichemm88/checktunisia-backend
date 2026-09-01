<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Jeton public d'une fiche — la cible du bouton « Consulter la fiche ».
 *
 * L'URL de base d'un bouton de modèle WhatsApp est FIGÉE à l'approbation
 * Meta : la changer impose de soumettre un nouveau modèle et d'attendre une
 * nouvelle validation. Pointer directement sur une page applicative
 * (`/authority/guests/{id}`) revenait donc à graver dans le marbre, chez un
 * tiers, une route interne que nous voudrons faire évoluer — notamment le
 * jour où une page « fiche » consultable par jeton signé existera.
 *
 * D'où une indirection : le modèle pointe sur `/f/{token}`, une route stable
 * qui redirige. Le jour où la destination change, seule cette route change.
 *
 * Pourquoi un jeton dédié plutôt que la clé primaire de la ligne :
 *
 *  - ce qui est PUBLIC doit être explicite. Une clé primaire circule dans les
 *    journaux, les écrans d'administration et les messages d'erreur ; elle
 *    n'a pas été choisie pour être publiée ;
 *  - un jeton peut être renouvelé pour invalider un lien diffusé par erreur,
 *    ce qu'une clé primaire ne permet pas.
 *
 * ULID : 26 caractères, 80 bits d'aléa — non énumérable — et triable par date
 * de création, ce qui aide au diagnostic.
 *
 * ── Pourquoi NOT NULL, et pourquoi le remplissage est ICI ──────────────────
 *
 * La colonne aurait pu rester nullable : les ~2 300 lignes déjà présentes
 * sont, pour l'essentiel, l'arriéré antérieur à la bascule, qui ne partira
 * jamais. L'argument est vrai et il est insuffisant.
 *
 * Un invariant qui ne tient que dans le modèle n'est pas un invariant : il
 * tient tant que TOUTES les écritures passent par Eloquent. La première
 * reprise de données en SQL, le premier `DB::table(...)->insert()`, le
 * premier `insertOrIgnore` écrit dans six mois produiraient une ligne sans
 * jeton — donc un bouton mort dans un message WhatsApp reçu par un policier,
 * découvert par lui et pas par nous.
 *
 * Le remplissage est donc dans la migration, et la contrainte est posée en
 * base APRÈS lui. Le crochet `creating` du modèle reste utile — il évite que
 * chaque appelant ait à y penser — mais il n'est plus ce sur quoi repose la
 * garantie.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Colonne d'abord nullable : la contrainte ne peut pas être posée
        //    tant que les lignes existantes n'ont pas de valeur.
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable();
        });

        // 2. Remplissage des lignes existantes. Par lots, et sans passer par
        //    le modèle : une migration doit survivre à la disparition ou au
        //    remaniement de la classe Eloquent.
        //
        //    Un ULID par ligne, généré en PHP plutôt qu'un gen_random_uuid()
        //    en une seule requête : le format reste homogène avec celui des
        //    nouvelles lignes. Un jeton est opaque, mais un jeton qui ne
        //    ressemble pas aux autres fait perdre du temps en diagnostic.
        //
        //    La boucle termine par construction : chaque passe réduit
        //    l'ensemble des lignes sans jeton.
        do {
            $ids = DB::table('whatsapp_send_log')
                ->whereNull('public_token')
                ->limit(500)
                ->pluck('id');

            foreach ($ids as $id) {
                DB::table('whatsapp_send_log')
                    ->where('id', $id)
                    ->update(['public_token' => (string) Str::ulid()]);
            }
        } while ($ids->isNotEmpty());

        // 3. La garantie, en base.
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable(false)->change();
        });

        // Unicité posée en dernier : sur des données déjà remplies, un doublon
        // ferait échouer la migration au lieu de passer inaperçu.
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->unique('public_token', 'uniq_wa_log_public_token');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->dropUnique('uniq_wa_log_public_token');
            $table->dropColumn('public_token');
        });
    }
};
