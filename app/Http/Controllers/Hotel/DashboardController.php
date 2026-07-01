<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Hotel;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel  = app('tenant');
        $today  = today();

        $arrivalsExpected = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        $arrivalsDone = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->where('status', 'active')
            ->count();

        $currentlyPresent = CheckIn::where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->count();

        $departuresToday = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('expected_check_out_date', $today)
            ->where('status', 'active')
            ->count();

        $monthTotal = CheckIn::where('hotel_id', $hotel->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $recentCheckIns = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'          => $c->id,
                'reference'   => $c->reference,
                'room'        => $c->room?->number,
                'status'      => $c->status,
                'primary_guest' => $c->guests->where('pivot.is_primary', true)->first()?->full_name,
                'check_in_date' => $c->check_in_date,
            ]);

        $sub = $hotel->activeSubscription;

        return response()->json([
            'data' => [
                'today' => [
                    'arrivals_expected' => $arrivalsExpected,
                    'arrivals_done'     => $arrivalsDone,
                    'currently_present' => $currentlyPresent,
                    'departures_today'  => $departuresToday,
                ],
                'month' => [
                    'check_ins_total' => $monthTotal,
                ],
                'subscription' => $sub ? [
                    'status'         => $sub->status,
                    'expires_at'     => $sub->expires_at,
                    'days_remaining' => $sub->days_remaining,
                    'plan'           => $sub->plan?->name,
                ] : ['status' => 'none'],
                'recent_check_ins' => $recentCheckIns,
            ],
        ]);
    }
}
