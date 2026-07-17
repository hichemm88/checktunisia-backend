<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TVA et timbre fiscal tunisiens paramétrables (chantier A2) : le taux et le
 * timbre vivent dans les réglages plateforme et alimentent la génération
 * automatique des factures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // TVA en pourcentage (19.00 = 19 %). 0 = non assujetti.
            $table->decimal('tax_rate', 5, 2)->default(0);
            // Timbre fiscal en TND par facture (1.000 TND en 2026). 0 = désactivé.
            $table->decimal('timbre_fiscal', 6, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'timbre_fiscal']);
        });
    }
};
