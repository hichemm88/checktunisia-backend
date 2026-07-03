<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make subscriptions.hotel_id nullable.
     *
     * Subscriptions are org-level. New self-registrations create a subscription
     * before any property exists, so hotel_id must allow NULL.
     * hotel_id is kept only for legacy compatibility — all subscription lookups
     * now use organization_id.
     */
    public function up(): void
    {
        // PostgreSQL: drop NOT NULL without touching the FK constraint
        DB::statement('ALTER TABLE subscriptions ALTER COLUMN hotel_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Only safe if no NULL values exist in the column
        DB::statement('ALTER TABLE subscriptions ALTER COLUMN hotel_id SET NOT NULL');
    }
};
