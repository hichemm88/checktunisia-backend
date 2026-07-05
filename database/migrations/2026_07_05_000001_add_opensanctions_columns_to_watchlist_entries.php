<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prepare watchlist_entries for system-generated entries (OpenSanctions sync).
 *
 * Changes:
 * - organization_id → nullable  (global entries don't belong to an authority org)
 * - added_by        → nullable  (system-created, no human actor)
 * - external_id     → new col   (OpenSanctions entity ID for deduplication)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('watchlist_entries', function (Blueprint $table) {
            // Allow NULL for system-generated entries (no authority org / no human actor)
            $table->foreignId('organization_id')->nullable()->change();
            $table->foreignUuid('added_by')->nullable()->change();

            // Unique external ID from OpenSanctions (e.g. "interpol-ABC123")
            // NULL for manual/import entries that have no external counterpart.
            $table->string('external_id', 100)->nullable()->unique()->after('import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('watchlist_entries', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
            $table->foreignId('organization_id')->nullable(false)->change();
            $table->foreignUuid('added_by')->nullable(false)->change();
        });
    }
};
