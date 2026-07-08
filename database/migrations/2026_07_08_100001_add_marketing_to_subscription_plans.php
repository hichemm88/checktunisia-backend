<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `marketing` jsonb column to subscription_plans holding the trilingual
 * display content shown on the public pricing cards (tier, display_name,
 * tagline, price_note, badge, featured, cta_label, bullets[]).
 *
 * Deliberately separate from `features`, which stays a functional-limits map
 * (max_users, ocr_scans_per_month) — the two have different consumers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->jsonb('marketing')->nullable();
        });

        // Backfill the 3 known plans with the content currently hardcoded on
        // the landing page (FR) + its EN/AR translations.
        foreach (SubscriptionPlanSeeder::marketingDefaults() as $slug => $marketing) {
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->update(['marketing' => json_encode($marketing, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('marketing');
        });
    }
};
