<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Périodes pendant lesquelles le canal des fiches était INCAPABLE d'émettre.
 *
 * L'abandon d'un envoi au bout de 24 h suppose que ces 24 h ont été 24 h de
 * tentatives. Ce n'était pas le cas : pendant l'attente d'approbation du
 * modèle chez Meta, la file ne pouvait rien tenter, et pourtant l'horloge
 * tournait — des fiches ont été déclarées « échec définitif » sans qu'une
 * seule tentative ait eu la moindre chance d'aboutir.
 *
 * Cette table est le registre qui permet de retrancher ce temps-là. Elle est
 * alimentée par la boucle d'envoi, à chaque passe : une période s'ouvre quand
 * le garde-fou refuse d'émettre, se ferme quand il rouvre.
 *
 * Elle est aussi une trace auditable. Sur un canal qui porte une obligation
 * légale, « pourquoi cette fiche a-t-elle mis trois jours à partir ? » doit
 * avoir une réponse écrite, pas une reconstitution à partir des journaux
 * d'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_channel_outages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->timestamp('started_at');

            // Null = période encore ouverte. Il ne peut y en avoir qu'une.
            $table->timestamp('ended_at')->nullable();

            // Le motif tel que le garde-fou l'a formulé, en clair : c'est ce
            // qui rend le registre lisible sans avoir à recouper avec le code.
            $table->text('reason');

            $table->timestamps();

            // Le seul accès est « les périodes qui chevauchent [a, b] ».
            $table->index(['started_at', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_channel_outages');
    }
};
