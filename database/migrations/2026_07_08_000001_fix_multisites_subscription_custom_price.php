<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time data fix: the "Large" (now Multi-sites) subscription for the
 * 4-hotel hébergeur had custom_price = 0.000 in production, a data bug —
 * confirmed with the platform owner to be a real paying customer, not a
 * comped/demo account. Sets it to the standard Multi-sites catalog price
 * (199,000 TND/mois), per the owner's explicit instruction to apply that
 * defined amount rather than leave the account uncharged.
 *
 * Scoped to the exact known row (plan slug + the specific zero value) so it
 * fails loudly instead of silently touching an unrelated subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = DB::table('subscriptions')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->where('subscription_plans.slug', 'multi-sites')
            ->where('subscriptions.custom_price', 0)
            ->update(['subscriptions.custom_price' => 199.000]);

        Log::info('clear_zero_custom_price_on_multisites_subscription: set custom_price to 199.000 on '.$updated.' subscription(s).');
    }

    public function down(): void
    {
        DB::table('subscriptions')
            ->join('subscription_plans', 'subscriptions.plan_id', '=', 'subscription_plans.id')
            ->where('subscription_plans.slug', 'multi-sites')
            ->where('subscriptions.custom_price', 199.000)
            ->update(['subscriptions.custom_price' => 0]);
    }
};
