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
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'scope', 'min_rooms', 'max_rooms',
                   'price_monthly', 'price_yearly', 'currency', 'features', 'marketing',
                   'included_properties', 'extra_property_price']);

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
