<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CMS : pages dynamiques éditées avec Puck (contenu JSON par langue),
 * menus publics (navbar/footer) et médias.
 *
 * Les médias vivent en base (bytea) et sont servis via
 * GET /public/media/{id} avec un Cache-Control long : le disque local de
 * Railway est éphémère et aucun bucket S3 n'est configuré en production —
 * Postgres est le seul stockage durable disponible aujourd'hui. Les images
 * sont redimensionnées/compressées à l'upload (intervention/image), et les
 * URLs restent stables si on migre vers S3 plus tard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('slug', 150)->unique();
            $table->string('status', 20)->default('draft'); // draft | published
            $table->jsonb('content')->nullable();           // { fr: PuckData, en: ..., ar: ... }
            $table->jsonb('meta')->nullable();               // { fr: {title, description}, ... }
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('location', 20);                  // navbar | footer
            $table->jsonb('label');                          // { fr, en, ar }
            $table->uuid('page_id')->nullable();
            $table->foreign('page_id')->references('id')->on('pages')->nullOnDelete();
            $table->string('external_url', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('filename', 255);
            $table->string('mime', 100);
            $table->integer('size');
            $table->binary('data');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('pages');
    }
};
