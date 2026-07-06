<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Invoices are billed at the hébergeur (organization) level, same as
     * subscriptions — see 2026_07_03_200001_make_subscription_hotel_id_nullable.php.
     * hotel_id is kept only for legacy standalone-hotel invoices; the org is
     * derived via invoice->subscription->organization_id.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices ALTER COLUMN hotel_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoices ALTER COLUMN hotel_id SET NOT NULL');
    }
};
