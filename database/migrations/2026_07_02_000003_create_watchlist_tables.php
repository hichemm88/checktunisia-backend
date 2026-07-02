<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Watchlist entries — persons flagged by authority organizations ──
        Schema::create('watchlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('organization_id')->constrained('authority_organizations');
            $table->foreignUuid('added_by')->constrained('users');

            // ── Identification criteria (at least one required) ─────────────
            $table->string('document_number', 100)->nullable();
            $table->string('document_type', 50)->nullable();   // passport | national_id | null = any
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->char('nationality_code', 3)->nullable();

            // ── Alert metadata ─────────────────────────────────────────────
            $table->string('severity', 20)->default('moyen');       // critique | eleve | moyen
            $table->text('reason')->nullable();                      // ministry-only confidential note
            $table->string('reason_code', 50)->default('AUTRE');    // MANDAT_ARRET | FRAUDE | MIGRATION | AUTRE

            // ── Lifecycle ──────────────────────────────────────────────────
            $table->string('status', 20)->default('active');        // active | inactive
            $table->timestamp('expires_at')->nullable();

            // ── Source ─────────────────────────────────────────────────────
            $table->string('source', 50)->default('manual');        // manual | import
            $table->string('import_batch_id', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_number'],                'idx_watchlist_doc');
            $table->index(['last_name', 'date_of_birth'],    'idx_watchlist_name_dob');
            $table->index(['organization_id'],                'idx_watchlist_org');
            $table->index(['status'],                         'idx_watchlist_status');
        });

        // ── Watchlist hits — triggered when a flagged person checks into a hotel ──
        Schema::create('watchlist_hits', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('watchlist_entry_id')->constrained('watchlist_entries')->cascadeOnDelete();
            $table->foreignUuid('guest_id')->constrained('guests');
            $table->foreignUuid('check_in_id')->constrained('check_ins');
            $table->foreignUuid('hotel_id')->constrained('hotels');
            $table->string('hit_type', 50)->default('document');    // document | name_dob | name_nationality
            $table->timestamp('notified_hotel_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignUuid('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['watchlist_entry_id', 'guest_id', 'check_in_id'], 'unique_watchlist_hit');
            $table->index(['hotel_id', 'acknowledged_at'],   'idx_wh_hotel_ack');
            $table->index(['guest_id'],                       'idx_wh_guest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_hits');
        Schema::dropIfExists('watchlist_entries');
    }
};
