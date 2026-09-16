<?php

namespace App\Http\Controllers\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Services\Subscription\CheckinQuota;
use App\Services\Subscription\PlanEntitlements;
use Illuminate\Http\JsonResponse;

class PartnerEstablishmentController extends Controller
{
    public function index(): JsonResponse
    {
        $partner = app(ApiPartner::class);

        $links = $partner->establishmentLinks()->active()->with('hotel.organization')->get();

        $data = $links->map(function ($link) {
            $hotel = $link->hotel;
            $org = $hotel->organization;
            $summary = $org ? PlanEntitlements::summary($org)['checkins_per_month'] : ['limit' => null, 'used' => 0];

            return [
                'establishment_id' => $hotel->id,
                'name' => $hotel->name,
                'status' => $hotel->status,
                'quota' => [
                    'used' => $summary['used'],
                    'limit' => $summary['limit'],
                    'period' => 'monthly',
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }
}
