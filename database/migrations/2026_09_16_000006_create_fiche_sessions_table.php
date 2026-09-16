<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session widget (modèle Stripe Checkout) : créée par POST /v1/fiche-sessions,
 * consommée par le widget embarqué. `prefill_guests` porte les données brutes
 * envoyées par le partenaire tant que le widget n'a pas encore été ouvert —
 * aucun Guest/TravelDocument n'est créé avant l'ouverture réelle du widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiche_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('hotel_id')->constrained('hotels')->cascadeOnDelete();
            $table->foreignUuid('partner_id')->constrained('api_partners')->cascadeOnDelete();
            $table->foreignUuid('api_key_id')->constrained('api_keys');

            $table->string('booking_reference');
            $table->string('mode', 10); // create | amend
            $table->string('status', 12)->default('pending'); // pending | submitted | expired

            // Rempli en mode create dès la création de la session (fiche brouillon) ;
            // rempli en mode amend dès la création (fiche déjà active/complétée réutilisée).
            $table->foreignUuid('check_in_id')->nullable()->constrained('check_ins')->nullOnDelete();

            $table->jsonb('prefill_guests')->default('[]');
            $table->date('arrival_date')->nullable();
            $table->date('departure_date')->nullable();
            $table->string('room_label')->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->boolean('is_test')->default(false);

            // JWT du widget : usage unique, suivi par jti.
            $table->string('jwt_jti', 40)->unique();
            $table->timestamp('jwt_consumed_at')->nullable();
            $table->string('widget_session_token_hash', 64)->nullable();
            $table->timestamp('widget_session_expires_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // Idempotence : un seul enregistrement "en cours" par (établissement, booking_ref).
            // La contrainte réelle d'unicité applicative est portée par FicheSessionService
            // (verrou + relecture), cet index sert la recherche rapide.
            $table->index(['hotel_id', 'booking_reference']);
            $table->index(['status', 'expires_at']);
            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_sessions');
    }
};
