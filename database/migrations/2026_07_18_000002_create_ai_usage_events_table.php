<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un enregistrement par appel a l'API Anthropic (Claude vision) : scan CIN et
 * repli passeport.
 *
 * Conformite INPDP / loi 2004-63 : AUCUNE donnee personnelle du voyageur. Pas de
 * nom, pas de numero CIN, pas d'image, pas de payload. Uniquement des metadonnees
 * techniques et comptables.
 *
 * `cost_usd` est FIGE a l'insertion (snapshot du tarif du moment) : un changement
 * de tarif ulterieur ne reecrit jamais l'historique.
 *
 * `hotel_id` porte l'etablissement a l'origine du scan (les endpoints l'exposent
 * sous le nom `establishment_id`, le vocabulaire cote produit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            // Operateur hotelier ayant declenche le scan. Nullable : resolu
            // best-effort cote serveur, jamais le voyageur.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('feature');            // cin_scan | passport_scan (extensible)
            $table->string('model');              // modele reellement utilise (reponse API)
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0); // fige a l'insert
            $table->string('status');             // success | api_error | parse_error
            $table->unsignedInteger('latency_ms')->default(0);
            $table->timestamp('created_at')->index();

            // Index composites demandes par le schema.
            $table->index(['hotel_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
