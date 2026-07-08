<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills marketing->price_note_yearly on the 3 seeded plans — the note
 * shown under the price when the landing's Mensuel/Annuel toggle is on
 * yearly (e.g. "par établissement / an · 12 mois au prix de 11"). Rows
 * whose marketing was already customised keep everything else untouched;
 * only this missing key is merged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (SubscriptionPlanSeeder::marketingDefaults() as $slug => $defaults) {
            if (empty($defaults['price_note_yearly'])) {
                continue;
            }
            $row = DB::table('subscription_plans')->where('slug', $slug)->first(['id', 'marketing']);
            if (!$row) {
                continue;
            }
            $marketing = $row->marketing ? json_decode($row->marketing, true) : [];
            if (isset($marketing['price_note_yearly'])) {
                continue; // already set (e.g. fresh install seeded after this change)
            }
            $marketing['price_note_yearly'] = $defaults['price_note_yearly'];
            DB::table('subscription_plans')->where('id', $row->id)
                ->update(['marketing' => json_encode($marketing, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(SubscriptionPlanSeeder::marketingDefaults()) as $slug) {
            $row = DB::table('subscription_plans')->where('slug', $slug)->first(['id', 'marketing']);
            if (!$row || !$row->marketing) {
                continue;
            }
            $marketing = json_decode($row->marketing, true);
            unset($marketing['price_note_yearly']);
            DB::table('subscription_plans')->where('id', $row->id)
                ->update(['marketing' => json_encode($marketing, JSON_UNESCAPED_UNICODE)]);
        }
    }
};
