<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API publique v1 — une ligne par plateforme tierce intégrée (ex: Diar).
 * Les clés API vivent dans api_keys (une ligne par clé live/test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_partners', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active'); // active | suspended
            $table->string('auth_mode', 20)->default('api_key'); // api_key (v2: oauth)

            // Origines autorisées à charger le widget dans une iframe (CSP frame-ancestors).
            $table->jsonb('allowed_widget_origins')->default('[]');

            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_partners');
    }
};
