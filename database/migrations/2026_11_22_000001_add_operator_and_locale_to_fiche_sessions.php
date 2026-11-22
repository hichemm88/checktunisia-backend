<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API v1.1 : opérateur (réceptionniste) désigné par le partenaire, et langue
 * du widget. Colonnes facultatives — aucune session existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->foreignUuid('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name', 150)->nullable();
            $table->string('locale', 2)->default('fr');
        });
    }

    public function down(): void
    {
        Schema::table('fiche_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operator_user_id');
            $table->dropColumn(['operator_name', 'locale']);
        });
    }
};
