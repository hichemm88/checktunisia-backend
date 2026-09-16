<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque les comptes utilisateurs synthétiques créés pour représenter une
 * intégration partenaire (API publique) — un par organisation, jamais une
 * vraie personne. Additif : ne touche à aucune valeur existante de `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system_actor')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system_actor');
        });
    }
};
