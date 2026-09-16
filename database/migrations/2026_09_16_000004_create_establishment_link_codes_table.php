<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code de liaison à usage unique généré par l'owner d'un établissement
 * (dashboard Intégrations), échangé une seule fois par un partenaire via
 * POST /v1/establishment-links. Seul le hash est stocké.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_link_codes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users');

            $table->string('code_hash', 64)->unique(); // sha256 hex
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignUuid('consumed_by_partner_id')->nullable()->constrained('api_partners')->nullOnDelete();

            $table->timestamps();

            $table->index(['hotel_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_link_codes');
    }
};
