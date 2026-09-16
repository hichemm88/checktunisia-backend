<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal + file d'attente des livraisons webhook partenaire (même pattern
 * outbox que whatsapp_send_log : BIGSERIAL, volume interne/journal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('endpoint_id')->constrained('partner_webhook_endpoints')->cascadeOnDelete();

            $table->string('event_type', 40); // fiche.submitted | fiche.failed | session.expired
            $table->string('idempotency_key')->nullable(); // évite le double-enfilage d'un même événement logique
            $table->jsonb('payload');

            $table->string('status', 12)->default('pending'); // pending | sent | failed | cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at', 'queued_at'], 'idx_partner_wh_dispatchable');
            $table->index('endpoint_id');
            $table->unique(['endpoint_id', 'event_type', 'idempotency_key'], 'uniq_partner_wh_idempotency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_deliveries');
    }
};
