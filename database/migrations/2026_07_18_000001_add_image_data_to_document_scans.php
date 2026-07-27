<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Copie compressée de l'image du document en base, car le disque Railway est
 * ÉPHÉMÈRE : chaque redéploiement efface les fichiers de scans, et les fiches
 * WhatsApp partaient alors sans photo. Stockée en base64 dans une colonne
 * text (l'insertion de binaire brut dans un bytea échoue avec pdo_pgsql —
 * octets nuls dans le paramètre). ~400 Ko par image (1600 px JPEG q80),
 * purgée après 24 h par whatsapp:purge-images — volume négligeable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->longText('image_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_scans', function (Blueprint $table) {
            $table->dropColumn('image_data');
        });
    }
};
