<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * File d'attente ET journal des envois WhatsApp en une seule table
 * (« pattern outbox » — rester simple, un seul destinataire). Les lignes en
 * statut `pending` constituent la file ; une fois envoyées/échouées, elles
 * restent comme journal (écran admin, bouton « Renvoyer », preuve d'envoi).
 *
 * La photo du document n'est jamais dupliquée : on référence le scan déjà
 * stocké dans Qayed (document_scans) — le worker la récupère à la demande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_send_log', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // property_id / checkin_id / traveler_id (§5). Le log survit à la
            // suppression du check-in/voyageur → nullOnDelete plutôt que cascade.
            // Nullable : un message de test [TEST] n'est rattaché à aucune propriété.
            $table->foreignUuid('hotel_id')->nullable()->constrained('hotels')->cascadeOnDelete();
            $table->foreignUuid('check_in_id')->nullable()->constrained('check_ins')->nullOnDelete();
            $table->foreignUuid('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            // Référence à la photo déjà stockée (jamais de copie).
            $table->foreignUuid('scan_id')->nullable()->constrained('document_scans')->nullOnDelete();

            // Destinataire porté PAR LE JOB (et non déduit dans le worker) : le
            // passage futur au multi-destinataires ne touchera que l'enfilage.
            $table->string('recipient');

            // Fiche pré-formatée envoyée en légende de la photo.
            $table->text('caption');

            $table->string('status', 12)->default('pending'); // pending|sent|failed|cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Fiche factice de test [TEST] — à ignorer dans les statistiques.
            $table->boolean('is_test')->default(false);

            // Prochaine tentative dispatchable (NULL = immédiatement). Sert au
            // backoff exponentiel côté planification (Laravel).
            $table->timestamp('next_attempt_at')->nullable();

            // Verrou FIFO : un worker « réclame » la ligne avant d'envoyer, pour
            // garantir un seul envoi à la fois même si le worker redémarre.
            $table->timestamp('claimed_at')->nullable();

            // Preuve d'envoi renvoyée par WhatsApp.
            $table->string('message_id_whatsapp')->nullable();

            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Claim FIFO : pending prêtes à partir, plus anciennes d'abord.
            $table->index(['status', 'next_attempt_at', 'queued_at'], 'idx_wa_log_dispatchable');
            $table->index(['hotel_id'], 'idx_wa_log_hotel');
            $table->index(['status'], 'idx_wa_log_status');
            $table->index(['created_at'], 'idx_wa_log_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_send_log');
    }
};
