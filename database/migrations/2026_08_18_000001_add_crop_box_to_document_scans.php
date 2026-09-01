<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadre du document détecté dans la photo, en fractions de l'image (0–1).
 *
 * Mémorisé sur le scan et non recalculé à chaque rendu : la détection coûte un
 * appel au modèle, et la même pièce est rendue plusieurs fois — relais
 * WhatsApp, export par établissement, récapitulatif quotidien. Une colonne
 * plutôt qu'un cache : la valeur doit survivre aux redéploiements, comme
 * l'image qu'elle décrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->json('crop_box')->nullable();

            // Distingue « jamais tenté » de « tenté sans succès ». Sans cette
            // date, un document que le modèle n'arrive pas à cadrer serait
            // resoumis à chaque rendu, indéfiniment.
            $table->timestamp('crop_detected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->dropColumn(['crop_box', 'crop_detected_at']);
        });
    }
};
