<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual bank-transfer (virement) support, reusing the Payment model rather
 * than a new table. Two new columns for the hébergeur-declared reference;
 * hotel_id becomes nullable since admin-created invoices are org-level
 * (hotel_id null) — the existing NOT NULL constraint would make every
 * virement payment against one of those invoices impossible to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: drop NOT NULL without touching the FK constraint (same
        // approach as 2026_07_03_200001_make_subscription_hotel_id_nullable,
        // avoids requiring doctrine/dbal for a Blueprint::change()).
        DB::statement('ALTER TABLE payments ALTER COLUMN hotel_id DROP NOT NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->string('declared_reference', 100)->nullable()->after('provider_tracking_id');
            $table->timestamp('declared_at')->nullable()->after('declared_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['declared_reference', 'declared_at']);
        });

        // Only safe if no NULL values exist in the column
        DB::statement('ALTER TABLE payments ALTER COLUMN hotel_id SET NOT NULL');
    }
};
