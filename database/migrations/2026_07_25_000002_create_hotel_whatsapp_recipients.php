<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement établissement → agents destinataires WhatsApp (Phase 2 de l'envoi
 * direct). Un établissement AVEC au moins un destinataire ici envoie ses fiches
 * directement à ces agents ; SANS aucun, il retombe sur le numéro global actuel
 * (config whatsapp.recipient) — donc bascule progressive, établissement par
 * établissement, sans rien casser pour les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_whatsapp_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignId('authority_user_profile_id')->constrained('authority_user_profiles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['hotel_id', 'authority_user_profile_id'], 'uniq_hotel_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_whatsapp_recipients');
    }
};
