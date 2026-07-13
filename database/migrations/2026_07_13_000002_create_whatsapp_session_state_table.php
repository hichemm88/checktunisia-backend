<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * État courant de la session WhatsApp (ligne unique, clé « default »).
 * - Le service Node y écrit les événements ready/disconnected/auth_failure.
 * - Laravel le lit pour /health/whatsapp et pour décider si un job est
 *   dispatchable (session `ready` et non `paused`).
 * - `paused` : coupe-circuit admin (POST /admin/whatsapp/pause) sans redéploiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_session_state', function (Blueprint $table) {
            $table->string('key')->primary(); // toujours 'default' (mono-session)
            $table->string('status', 20)->default('initializing'); // initializing|ready|disconnected|auth_failure
            $table->text('reason')->nullable();
            $table->boolean('paused')->default(false);
            $table->timestamp('last_ready_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable(); // dernier signe de vie du worker
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_session_state');
    }
};
