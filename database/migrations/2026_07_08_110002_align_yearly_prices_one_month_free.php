<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns yearly pricing on the "1 mois offert" rule (yearly = 11 × monthly,
 * introduced with the pricing toggle): the 3 seeded plans carried an explicit
 * price_yearly at 10 × monthly (the older "-2 months" rule), which overrides
 * the 11 × fallback via effective_price_yearly and contradicts the "12 mois
 * au prix de 11" copy shown on the landing and register pages.
 *
 * Only rows still holding the exact old seeded values are touched — an
 * admin-customised yearly price is left alone. Nulling the column hands
 * pricing to the 11 × monthly rule.
 */
return new class extends Migration
{
    private const OLD_SEEDED_YEARLY = [
        'essentiel'   => '590.000',
        'pro'         => '1190.000',
        'multi-sites' => '1990.000',
    ];

    public function up(): void
    {
        foreach (self::OLD_SEEDED_YEARLY as $slug => $oldYearly) {
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->where('price_yearly', $oldYearly)
                ->update(['price_yearly' => null]);
        }
    }

    public function down(): void
    {
        foreach (self::OLD_SEEDED_YEARLY as $slug => $oldYearly) {
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->whereNull('price_yearly')
                ->update(['price_yearly' => $oldYearly]);
        }
    }
};
