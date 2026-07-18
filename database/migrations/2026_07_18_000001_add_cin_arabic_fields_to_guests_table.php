<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Champs arabes de la CIN tunisienne (préremplis par le scan Claude vision, puis
 * validés par la réceptionniste). Colonnes nullable → additif et rétro-compatible :
 * aucun impact sur la saisie passeport/MRZ existante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->string('last_name_ar', 150)->nullable();   // اللقب
            $table->string('first_name_ar', 150)->nullable();  // الاسم (premier prénom)
            $table->string('filiation_ar', 200)->nullable();   // بنت/بن + filiation
            $table->string('spouse_ar', 150)->nullable();      // texte après حرم
            $table->string('birth_place_ar', 150)->nullable(); // مكان الولادة
            // Format de la carte : legacy (non biométrique) | biometric (loi 2024-22).
            $table->string('card_format', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn([
                'last_name_ar',
                'first_name_ar',
                'filiation_ar',
                'spouse_ar',
                'birth_place_ar',
                'card_format',
            ]);
        });
    }
};
