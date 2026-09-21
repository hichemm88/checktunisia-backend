<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un prospect (établissement à démarcher). Enums stockés en chaînes plutôt
 * qu'en type Postgres natif, validées côté application — même convention que
 * `coupons.type` : ajouter une valeur ne demande pas de migration.
 *
 * Pipeline (`status`) : à_contacter → contacté → relancé → démo_planifiée →
 * démo_faite → essai_en_cours → client, plus les statuts terminaux refus /
 * sans_réponse / hors_périmètre. Voir App\Services\Prospection\PipelineStatus.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->string('name')->unique();
            $table->string('zone', 30)->default('autre'); // grand_tunis | banlieue_nord | cap_bon | sud | autre
            $table->string('locality')->nullable(); // ex. "Médina de Tunis"
            $table->text('address')->nullable(); // adresse / repère

            $table->string('size', 10)->nullable(); // petite | moyenne | grande
            $table->string('segment', 30)->nullable(); // maison_hotes | guesthouse | boutique_hotel | hotel | location_entiere | autre

            $table->string('priority', 2)->default('P2'); // P1 | P2 | P3
            $table->string('status', 20)->default('a_contacter');

            $table->string('decision_maker_name')->nullable();
            $table->string('decision_maker_role')->nullable();
            $table->string('whatsapp_phone', 20)->nullable(); // E.164, +216...
            $table->string('origin_channel')->nullable(); // texte libre : Messenger, réseau TLL, Google Maps...

            $table->text('qualification_notes')->nullable();
            $table->string('target_plan', 15)->default('inconnu'); // essentiel | pro | hotel | inconnu

            $table->timestamp('next_action_at')->nullable(); // moteur des relances

            $table->boolean('out_of_scope')->default(false); // ex. Dar Kasbahost
            $table->boolean('archived')->default(false);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['zone']);
            $table->index(['priority']);
            $table->index(['next_action_at']);
            $table->index(['archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishments');
    }
};
