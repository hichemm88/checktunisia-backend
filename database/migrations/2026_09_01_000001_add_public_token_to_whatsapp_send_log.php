<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
 * de création, ce qui aide au diagnostic. Nullable : les lignes de l'arriéré
 * antérieur à la bascule n'en ont pas besoin, elles ne partiront jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_send_log', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique('uniq_wa_log_public_token');
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
