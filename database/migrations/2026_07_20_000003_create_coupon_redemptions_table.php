<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trace d'utilisation d'un coupon : une ligne par application a une facture.
 * Sert a l'audit, au comptage des usages (max_uses) et a empecher qu'un meme
 * coupon soit applique deux fois a la meme facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->decimal('amount_discounted', 10, 3)->default(0);
            $table->foreignUuid('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['coupon_id', 'invoice_id'], 'uq_coupon_redemption_invoice');
            $table->index(['coupon_id'], 'idx_coupon_redemptions_coupon');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
