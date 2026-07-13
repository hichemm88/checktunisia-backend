<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Rend whatsapp_send_log.hotel_id nullable : un message de test [TEST] n'est
 * rattaché à aucune propriété. (Correctif pour les déploiements où la table a
 * déjà été créée avec hotel_id NOT NULL.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE whatsapp_send_log ALTER COLUMN hotel_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE whatsapp_send_log ALTER COLUMN hotel_id SET NOT NULL');
    }
};
