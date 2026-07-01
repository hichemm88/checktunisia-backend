<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MRZ encodes country codes as 3-letter ISO 3166-1 alpha-3 (TUN, FRA, DEU…).
 * The original column was char(2) which causes SQLSTATE[22001] on insert.
 * Widen to char(3) — non-destructive (existing 2-char data stays valid).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE travel_documents ALTER COLUMN issuing_country_code TYPE char(3)');
    }

    public function down(): void
    {
        // Trim back to 2 chars — may truncate existing 3-char values
        DB::statement('ALTER TABLE travel_documents ALTER COLUMN issuing_country_code TYPE char(2) USING left(issuing_country_code, 2)');
    }
};
