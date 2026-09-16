<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clés API partenaires (qyd_live_… / qyd_test_…). Seul le hash est stocké —
 * le clair n'est retourné qu'une fois, à l'émission (ApiKeyService::issue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('partner_id')->constrained('api_partners')->cascadeOnDelete();

            $table->string('mode', 10); // live | test
            $table->string('prefix', 16); // qyd_live_ / qyd_test_ + 6 premiers caractères, pour l'affichage admin
            $table->string('key_hash', 64)->unique(); // sha256 hex

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
