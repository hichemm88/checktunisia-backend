<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liaison active établissement ↔ partenaire. Un partenaire peut être lié à
 * plusieurs établissements (Diar : les six dars) ; un établissement peut être
 * lié à plusieurs partenaires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_partner_links', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignUuid('partner_id')->constrained('api_partners')->cascadeOnDelete();

            $table->timestamp('linked_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['hotel_id', 'partner_id']);
            $table->index(['partner_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_partner_links');
    }
};
