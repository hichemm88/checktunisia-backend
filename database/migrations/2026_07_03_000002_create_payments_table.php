<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(\Illuminate\Support\Facades\DB::raw('gen_random_uuid()'));
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();

            // Provider: flouci | manual
            $table->string('provider', 30)->default('flouci');
            // Flouci's internal payment ID (returned on creation)
            $table->string('provider_payment_id', 100)->nullable()->unique();
            // Our tracking ID sent to Flouci (developer_tracking_id)
            $table->string('provider_tracking_id', 100)->nullable();

            // pending | completed | failed | expired
            $table->string('status', 20)->default('pending');

            $table->decimal('amount', 10, 3);
            $table->char('currency', 3)->default('TND');

            // Redirect URL for the end-user to complete payment on Flouci
            $table->string('payment_url', 1000)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Raw JSON from the provider (for audit/support)
            $table->jsonb('provider_response')->default('{}');

            $table->timestamps();

            $table->index(['invoice_id'],           'idx_payments_invoice');
            $table->index(['hotel_id'],             'idx_payments_hotel');
            $table->index(['provider_payment_id'],  'idx_payments_provider_id');
            $table->index(['status'],               'idx_payments_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
