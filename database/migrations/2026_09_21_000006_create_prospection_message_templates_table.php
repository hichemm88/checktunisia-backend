<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèles de message WhatsApp/Messenger, édités depuis l'app. Rendus côté
 * frontend avec {prenom}/{etablissement} avant d'ouvrir wa.me — voir
 * ProspectionMessageTemplateSeeder pour la règle métier sur leur contenu.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->text('body');
            $table->string('segment', 30)->nullable(); // segment cible, nullable = tous
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
