<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\WatchlistHit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel  = app('tenant');
        $today  = today();

        // ── Today metrics ─────────────────────────────────────────────────────
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

        // ── Month total ───────────────────────────────────────────────────────
        $monthTotal = CheckIn::where('hotel_id', $hotel->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // ── Occupancy rate ────────────────────────────────────────────────────
        $occupancyRate = $hotel->room_count > 0
            ? round(($currentlyPresent / $hotel->room_count) * 100)
            : 0;

        // ── Weekly trend (last 7 days) ────────────────────────────────────────
        $weeklyRaw = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', '>=', $today->copy()->subDays(6))
            ->whereDate('check_in_date', '<=', $today)
            ->select(DB::raw('DATE(check_in_date) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $weekly = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $weekly[] = [
                'date'  => $d->format('Y-m-d'),
                'label' => $d->locale('fr')->isoFormat('ddd D'),
                'count' => (int) ($weeklyRaw[$d->format('Y-m-d')] ?? 0),
            ];
        }

        // ── Document expiry alerts (next 30 days) ─────────────────────────────
        $expiryAlerts = TravelDocument::join('guests', 'travel_documents.guest_id', '=', 'guests.id')
            ->join('check_in_guests', 'guests.id', '=', 'check_in_guests.guest_id')
            ->join('check_ins', 'check_in_guests.check_in_id', '=', 'check_ins.id')
            ->where('check_ins.hotel_id', $hotel->id)
            ->where('check_ins.status', 'active')
            ->whereNotNull('travel_documents.expiry_date')
            ->whereDate('travel_documents.expiry_date', '>=', $today)
            ->whereDate('travel_documents.expiry_date', '<=', $today->copy()->addDays(30))
            ->orderBy('travel_documents.expiry_date')
            ->limit(5)
            ->select([
                'guests.first_name', 'guests.last_name',
                'travel_documents.document_number', 'travel_documents.expiry_date',
                'check_ins.id as check_in_id', 'check_ins.reference',
            ])
            ->get()
            ->map(fn($row) => [
                'guest_name'      => trim("{$row->first_name} {$row->last_name}"),
                'document_number' => $row->document_number,
                'expiry_date'     => $row->expiry_date,
                'days_until_expiry' => (int) Carbon::parse($row->expiry_date)->diffInDays($today, false) * -1,
                'check_in_id'     => $row->check_in_id,
                'reference'       => $row->reference,
            ]);

        // ── Recent check-ins (today) ──────────────────────────────────────────
        $recentCheckIns = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'reference'     => $c->reference,
                'room'          => $c->room?->number,
                'status'        => $c->status,
                'primary_guest' => $c->guests->where('pivot.is_primary', true)->first()?->full_name,
                'check_in_date' => $c->check_in_date,
            ]);

        // ── Watchlist hits pending acknowledgement ────────────────────────────
        $pendingWatchlistHits = WatchlistHit::where('hotel_id', $hotel->id)
            ->whereNull('acknowledged_at')
            ->count();

        $sub = $hotel->activeSubscription;

        return response()->json([
            'data' => [
                'today' => [
                    'arrivals_expected' => $arrivalsExpected,
                    'arrivals_done'     => $arrivalsDone,
                    'currently_present' => $currentlyPresent,
                    'departures_today'  => $departuresToday,
                    'occupancy_rate'    => $occupancyRate,
                ],
                'month' => [
                    'check_ins_total' => $monthTotal,
                ],
                'weekly_trend'   => $weekly,
                'expiry_alerts'  => $expiryAlerts,
                'subscription' => $sub ? [
                    'status'         => $sub->status,
                    'expires_at'     => $sub->expires_at,
                    'days_remaining' => $sub->days_remaining,
                    'plan'           => $sub->plan?->name,
                ] : ['status' => 'none'],
                'recent_check_ins'         => $recentCheckIns,
                'pending_watchlist_hits'   => $pendingWatchlistHits,
            ],
        ]);
    }
}
