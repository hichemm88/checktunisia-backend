<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remise appliquee a une facture via un code promo. `discount_amount` est un
 * montant HT deduit ; `coupon_code` est denormalise pour l'affichage (le lien
 * complet vit dans coupon_redemptions).
 *
 * total_amount = amount + tax_amount - discount_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 3)->default(0)->after('tax_amount');
            $table->string('coupon_code', 40)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'coupon_code']);
        });
    }
};
