<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Codes promo appliques aux factures (remise en pourcentage ou montant fixe TND).
 * La remise porte sur le montant HT de la facture, avant taxe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 40)->unique();          // stocke en MAJUSCULES
            $table->string('type', 10);                    // percent | fixed
            $table->decimal('value', 10, 3);               // % (0-100) ou montant TND
            $table->string('description', 200)->nullable();
            $table->decimal('min_amount', 10, 3)->nullable();   // montant HT minimum pour appliquer
            $table->unsignedInteger('max_uses')->nullable();    // null = illimite
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();        // null = pas d'expiration
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active'], 'idx_coupons_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
