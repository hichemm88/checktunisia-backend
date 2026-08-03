<?php
namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class PublicPlatformController extends Controller
{
    /**
     * Public plans list — same as /subscriptions/plans but explicit /public prefix.
     */
    public function plans(): JsonResponse
    {
        // is_public : les plans legacy (Multi-sites) restent actifs pour leurs
        // abonnés mais disparaissent de la grille publique.
        $plans = SubscriptionPlan::where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'scope', 'min_rooms', 'max_rooms',
                   'price_monthly', 'price_yearly', 'currency', 'features', 'marketing',
                   'included_properties', 'extra_property_price',
                   'overage_price', 'overage_bundle_size']);

        return response()->json(['data' => $plans]);
    }

    /**
     * Public platform settings — only payment-method fields, no API credentials.
     */
    public function settings(): JsonResponse
    {
        $s = PlatformSetting::get();
        return response()->json(['data' => $s->toPublicArray()]);
    }
}
