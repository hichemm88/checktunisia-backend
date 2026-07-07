<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harmonizes the 3 subscription packs with the public pricing page:
 * Small/Medium/Large (room-count tiers, 25/85/250 TND) become
 * Essentiel/Pro/Multi-sites (feature tiers, 59/119/199 TND). Mutates the 3
 * existing rows in place rather than inserting new ones — only 2 live
 * subscriptions reference plan_id today, so there is nothing to preserve
 * by keeping the old rows around.
 *
 * Multi-sites is billed per-organization (unlimited hotels) rather than
 * per-hotel like the other two tiers, so `scope` records that distinction
 * for anything that gates on room-count tiering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('scope', 20)->default('hotel')->after('slug'); // hotel | organization
        });

        DB::table('subscription_plans')->where('slug', 'small')->update([
            'name' => 'Essentiel', 'slug' => 'essentiel', 'scope' => 'hotel',
            'price_monthly' => 59.000, 'price_yearly' => 590.000,
            'features' => json_encode(['max_users' => 2, 'ocr_scans_per_month' => 100]),
        ]);
        DB::table('subscription_plans')->where('slug', 'medium')->update([
            'name' => 'Pro', 'slug' => 'pro', 'scope' => 'hotel',
            'price_monthly' => 119.000, 'price_yearly' => 1190.000,
            'features' => json_encode(['max_users' => 5, 'ocr_scans_per_month' => -1]),
        ]);
        DB::table('subscription_plans')->where('slug', 'large')->update([
            'name' => 'Multi-sites', 'slug' => 'multi-sites', 'scope' => 'organization',
            'min_rooms' => 1, 'max_rooms' => null,
            'price_monthly' => 199.000, 'price_yearly' => 1990.000,
            'features' => json_encode(['max_users' => -1, 'ocr_scans_per_month' => -1]),
        ]);
    }

    public function down(): void
    {
        DB::table('subscription_plans')->where('slug', 'essentiel')->update([
            'name' => 'Small', 'slug' => 'small', 'scope' => 'hotel',
            'price_monthly' => 25.000, 'price_yearly' => 250.000,
            'features' => json_encode(['max_users' => 3, 'ocr_scans_per_month' => 200]),
        ]);
        DB::table('subscription_plans')->where('slug', 'pro')->update([
            'name' => 'Medium', 'slug' => 'medium', 'scope' => 'hotel',
            'price_monthly' => 85.000, 'price_yearly' => 850.000,
            'features' => json_encode(['max_users' => 10, 'ocr_scans_per_month' => 1000]),
        ]);
        DB::table('subscription_plans')->where('slug', 'multi-sites')->update([
            'name' => 'Large', 'slug' => 'large', 'scope' => 'hotel',
            'min_rooms' => 21, 'max_rooms' => null,
            'price_monthly' => 250.000, 'price_yearly' => null,
            'features' => json_encode(['max_users' => -1, 'ocr_scans_per_month' => -1]),
        ]);

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
