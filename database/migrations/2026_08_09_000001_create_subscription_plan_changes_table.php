<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Changements de plan demandés par le client (self-service).
 *
 * Un changement de plan n'est plus un message à l'administrateur : c'est un
 * objet métier qui a un cycle de vie propre, et qui porte à lui seul la
 * réponse à « pourquoi ce client est-il sur ce plan, depuis quand, et
 * qu'a-t-il payé pour ça ».
 *
 * `status` :
 *  - pending_payment  upgrade demandé, facture émise, plan PAS encore changé
 *                     (le plan ne bascule qu'au paiement confirmé) ;
 *  - scheduled        downgrade programmé pour la fin de la période payée ;
 *  - applied          effectivement appliqué (applied_at renseigné) ;
 *  - cancelled        abandonné par le client avant application ;
 *  - failed           facture annulée/expirée sans paiement.
 *
 * IDEMPOTENCE — deux garanties SQL, pas des conventions de code :
 *  1. `idempotency_key` unique : un double clic, un rejeu réseau ou deux
 *     onglets envoyant la même demande retombent sur la MÊME ligne, donc sur
 *     la même facture. Jamais deux factures pour une seule intention.
 *  2. un index unique partiel n'autorise qu'UN SEUL changement en cours
 *     (pending_payment ou scheduled) par abonnement : impossible d'empiler
 *     deux changements de plan concurrents, quel que soit le chemin.
 *
 * `from_conditions` fige ce que le client avait AVANT (prix, quota effectif,
 * overrides négociés). C'est ce qui permet d'expliquer après coup un
 * changement, et de prévenir un client historique de ce qu'il perd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // subscription_plans.id est un entier auto-incrémenté (schéma
            // historique), contrairement au reste du domaine en UUID.
            $table->foreignId('from_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('to_plan_id')->constrained('subscription_plans')->cascadeOnDelete();

            $table->string('kind', 20);                    // upgrade | downgrade
            $table->string('status', 20)->default('pending_payment');
            $table->timestamp('effective_at')->nullable(); // downgrade : fin de période payée
            $table->timestamp('applied_at')->nullable();

            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount_due', 10, 3)->default(0);      // à payer maintenant (upgrade)
            $table->decimal('credit_applied', 10, 3)->default(0);  // reliquat de la période en cours
            $table->decimal('next_renewal_amount', 10, 3)->default(0);

            $table->json('from_conditions')->nullable();
            // Un client historique doit avoir explicitement accepté de perdre
            // ses conditions négociées : la trace de ce consentement vit ici.
            $table->boolean('conditions_change_accepted')->default(false);

            $table->string('idempotency_key', 100);
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('idempotency_key', 'uq_plan_changes_idempotency');
            $table->index(['organization_id', 'created_at'], 'idx_plan_changes_org_created');
        });

        // Un seul changement en cours par abonnement (index partiel PostgreSQL).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_plan_changes_one_in_flight
            ON subscription_plan_changes (subscription_id)
            WHERE status IN ('pending_payment', 'scheduled')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_changes');
    }
};
