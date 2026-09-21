<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des actions de prospection, APPEND-ONLY (aucune UPDATE/DELETE
 * applicatif prévu — voir App\Http\Controllers\Prospection\ActionController).
 * Chaque changement de statut du pipeline y crée automatiquement une entrée
 * type=changement_statut.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('actions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();

            // message_envoye | reponse_recue | appel | demo_planifiee |
            // demo_faite | essai_active | relance | note | changement_statut
            $table->string('type', 25);
            $table->string('channel', 20)->nullable(); // WhatsApp | Messenger | téléphone | sur place
            $table->text('content')->nullable();

            // Tags du référentiel objections (labels, pas de FK : un tag
            // désactivé après coup ne doit pas invalider l'historique).
            $table->json('objections')->nullable();

            $table->timestamp('occurred_at')->useCurrent(); // modifiable (saisie a posteriori)
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['establishment_id', 'occurred_at']);
            $table->index(['type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
