<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('partner_id')->constrained('api_partners')->cascadeOnDelete();

            $table->string('url');
            $table->text('secret'); // chiffré (cast encrypted) — HMAC a besoin du clair
            $table->jsonb('events')->default('["fiche.submitted","fiche.failed","session.expired"]');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();

            $table->index(['partner_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_endpoints');
    }
};
