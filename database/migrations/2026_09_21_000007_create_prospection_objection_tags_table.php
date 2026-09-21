<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel éditable des tags d'objection, utilisés sur les actions de
 * type démo/appel/relance pour compter les objections les plus fréquentes
 * (tableau de bord). Édition réservée à l'admin.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('objection_tags', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('label')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objection_tags');
    }
};
