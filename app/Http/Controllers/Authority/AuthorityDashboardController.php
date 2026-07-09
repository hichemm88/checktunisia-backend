<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthorityDashboardController extends Controller
{
    /**
     * GET /authority/dashboard
     * Returns different payloads based on organization type:
     *   - ministry  → national statistics across all Tunisia
     *   - police    → zone statistics scoped to the officer's governorate
     */
    public function dashboard(Request $request): JsonResponse
    {
        $profile     = $this->getProfile($request);
        $isMinistry  = ($profile['org_type'] ?? null) === 'ministry';
        $governorate = $profile['governorate'] ?? null;

        if ($isMinistry) {
            return response()->json(['data' => $this->nationalStats()]);
        }

        return response()->json(['data' => $this->zoneStats($governorate)]);
    }

    /**
     * GET /authority/alerts
     * Documents expiring within 30 days, scoped to the user's zone.
     */
    public function alerts(Request $request): JsonResponse
    {
        // Feature temporarily disabled — return an empty payload without hitting
        // the DB. Flip config('features.expired_document_alerts') to restore.
        if (!config('features.expired_document_alerts')) {
            return response()->json(['data' => [], 'meta' => ['disabled' => true]]);
        }

        $profile     = $this->getProfile($request);
        $isMinistry  = ($profile['org_type'] ?? null) === 'ministry';
        $governorate = $profile['governorate'] ?? null;

        $query = TravelDocument::with(['guest', 'guest.checkIns' => function ($q) {
            $q->where('status', 'active')->with('hotel.address', 'room');
        }])
        ->whereNotNull('expiry_date')
        ->whereDate('expiry_date', '>=', now()->toDateString())
        ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString());

        // Scope to guests with an active check-in in this zone
        $query->whereHas('guest.checkIns', function ($ci) use ($isMinistry, $governorate) {
            $ci->where('status', 'active');
            if (!$isMinistry && $governorate) {
                $ci->whereHas('hotel.address', fn($a) =>
                    $a->where('governorate', $governorate)
                );
            }
        });

        $docs = $query->orderBy('expiry_date')->limit(100)->get();

        return response()->json([
            'data' => $docs->map(function ($doc) {
                $activeStay = $doc->guest?->checkIns
                    ->where('status', 'active')->first();

                return [
                    'doc_id'          => $doc->id,
                    'guest_id'        => $doc->guest_id,
                    'guest_name'      => $doc->guest ? "{$doc->guest->first_name} {$doc->guest->last_name}" : null,
                    'nationality_code'=> $doc->guest?->nationality_code,
                    'document_type'   => $doc->type,
                    'document_number' => $doc->document_number,
                    'expiry_date'     => $doc->expiry_date,
                    'days_until_expiry' => now()->diffInDays($doc->expiry_date, false),
                    'hotel'           => $activeStay ? [
                        'name'        => $activeStay->hotel?->name,
                        'city'        => $activeStay->hotel?->address?->city,
                        'governorate' => $activeStay->hotel?->address?->governorate,
                    ] : null,
                    'room_number'     => $activeStay?->room?->number,
                    'check_in_id'     => $activeStay?->id,
                ];
            }),
            'meta' => ['governorate' => $governorate, 'is_national' => $isMinistry],
        ]);
    }

    /**
     * GET /authority/activity
     * Audit log of authority searches — ministry sees all orgs, police sees own.
     */
    public function activity(Request $request): JsonResponse
    {
        $profile    = $this->getProfile($request);
        $isMinistry = ($profile['org_type'] ?? null) === 'ministry';

        $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
        ]);

        // Ministry: all authority searches; Police: only own user's searches
        $query = AuditLog::with('actor')
            ->where('action', 'LIKE', 'authority.%')
            ->orderByDesc('created_at');

        if (!$isMinistry) {
            // Police can only see their own activity
            $query->where('actor_id', $request->user()->id);
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to . ' 23:59:59');
        }

        $logs = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $logs->map(fn($log) => [
                'id'          => $log->id,
                'action'      => $log->action,
                'actor_name'  => $log->actor ? "{$log->actor->first_name} {$log->actor->last_name}" : 'Inconnu',
                'actor_role'  => $log->actor_role,
                'new_values'  => $log->new_values,  // contains search_params / result_count
                'ip_address'  => $log->ip_address,
                'created_at'  => $log->created_at,
            ]),
            'meta' => [
                'total'        => $logs->total(),
                'current_page' => $logs->currentPage(),
                'per_page'     => $logs->perPage(),
                'is_national'  => $isMinistry,
            ],
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function getProfile(Request $request): array
    {
        $user    = $request->user();
        $profile = $user->authorityProfile()->with('organization')->first();
        return [
            'org_type'    => $profile?->organization?->type,
            'governorate' => $profile?->organization?->governorate,
        ];
    }

    /**
     * National statistics for Ministère de l'Intérieur.
     */
    private function nationalStats(): array
    {
        $today = now()->toDateString();

        $activeGuests   = CheckIn::where('status', 'active')->whereHas('hotel')->count();
        $checkInsToday  = CheckIn::whereDate('check_in_date', $today)->whereHas('hotel')->count();
        $checkOutsToday = CheckIn::whereDate('actual_check_out_date', $today)->whereHas('hotel')->count();
        $activeHotels   = Hotel::where('status', 'active')->count();

        // Guests present by governorate. Raw query-builder joins bypass Eloquent's
        // SoftDeletingScope, so a deleted hotel must be excluded explicitly here —
        // it would otherwise keep contributing to these counts forever.
        $byGovernorat = DB::table('check_ins')
            ->join('hotels', 'check_ins.hotel_id', '=', 'hotels.id')
            ->join('hotel_addresses', 'hotels.id', '=', 'hotel_addresses.hotel_id')
            ->where('check_ins.status', 'active')
            ->whereNull('hotels.deleted_at')
            ->select(
                'hotel_addresses.governorate',
                DB::raw('COUNT(DISTINCT check_ins.id) as active_guests'),
                DB::raw('COUNT(DISTINCT hotels.id) as hotels')
            )
            ->groupBy('hotel_addresses.governorate')
            ->orderByDesc('active_guests')
            ->get();

        // Top nationalities currently present
        $topNationalities = DB::table('guests')
            ->join('check_in_guests', 'guests.id', '=', 'check_in_guests.guest_id')
            ->join('check_ins', 'check_in_guests.check_in_id', '=', 'check_ins.id')
            ->join('hotels', 'check_ins.hotel_id', '=', 'hotels.id')
            ->where('check_ins.status', 'active')
            ->whereNull('hotels.deleted_at')
            ->select('guests.nationality_code', DB::raw('COUNT(*) as count'))
            ->groupBy('guests.nationality_code')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Weekly check-in trend (last 7 days)
        $weeklyTrend = DB::table('check_ins')
            ->where('check_in_date', '>=', now()->subDays(6)->toDateString())
            ->select(DB::raw('check_in_date::date as date'), DB::raw('COUNT(*) as count'))
            ->groupBy(DB::raw('check_in_date::date'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trend = collect(range(6, 0))->map(function ($daysAgo) use ($weeklyTrend) {
            $date  = now()->subDays($daysAgo)->toDateString();
            $label = now()->subDays($daysAgo)->locale('fr')->isoFormat('ddd');
            return ['date' => $date, 'label' => $label, 'count' => $weeklyTrend[$date]->count ?? 0];
        })->values();

        // Expiring documents (next 30 days) — feature-flagged; skip the query when off.
        $expiringCount = config('features.expired_document_alerts')
            ? TravelDocument::whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->whereHas('guest.checkIns', fn($q) => $q->where('status', 'active')->whereHas('hotel'))
                ->count()
            : 0;

        return [
            'type'              => 'ministry',
            'active_guests'     => $activeGuests,
            'check_ins_today'   => $checkInsToday,
            'check_outs_today'  => $checkOutsToday,
            'active_hotels'     => $activeHotels,
            'expiring_docs_30d' => $expiringCount,
            'by_governorate'    => $byGovernorat,
            'top_nationalities' => $topNationalities,
            'weekly_trend'      => $trend,
        ];
    }

    /**
     * Zone statistics for a police station in a specific governorate.
     */
    private function zoneStats(?string $governorate): array
    {
        $today = now()->toDateString();

        $scope = function ($query) use ($governorate) {
            // whereHas('hotel') alone (no closure) already excludes check-ins
            // whose hotel has been soft-deleted — the governorate filter, when
            // present, is layered on top of that same relation.
            $query->whereHas('hotel', function ($h) use ($governorate) {
                if ($governorate) {
                    $h->whereHas('address', fn($a) => $a->where('governorate', $governorate));
                }
            });
        };

        $activeGuests   = CheckIn::where('status', 'active')->tap($scope)->count();
        $checkInsToday  = CheckIn::whereDate('check_in_date', $today)->tap($scope)->count();
        $checkOutsToday = CheckIn::whereDate('actual_check_out_date', $today)->tap($scope)->count();

        $hotelsInZone = Hotel::where('status', 'active')
            ->when($governorate, fn($q) =>
                $q->whereHas('address', fn($a) => $a->where('governorate', $governorate))
            )
            ->count();

        // Expiring docs in zone — feature-flagged; skip the query when off.
        $expiringCount = config('features.expired_document_alerts')
            ? TravelDocument::whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->whereHas('guest.checkIns', function ($q) use ($governorate) {
                    $q->where('status', 'active')->whereHas('hotel', function ($h) use ($governorate) {
                        if ($governorate) {
                            $h->whereHas('address', fn($a) => $a->where('governorate', $governorate));
                        }
                    });
                })
                ->count()
            : 0;

        // Nationalities present in zone. Raw joins bypass the hotel's
        // SoftDeletingScope, so a deleted hotel must be excluded explicitly.
        $nationalities = DB::table('guests')
            ->join('check_in_guests', 'guests.id', '=', 'check_in_guests.guest_id')
            ->join('check_ins', 'check_in_guests.check_in_id', '=', 'check_ins.id')
            ->join('hotels', 'check_ins.hotel_id', '=', 'hotels.id')
            ->join('hotel_addresses', 'hotels.id', '=', 'hotel_addresses.hotel_id')
            ->where('check_ins.status', 'active')
            ->whereNull('hotels.deleted_at')
            ->when($governorate, fn($q) => $q->where('hotel_addresses.governorate', $governorate))
            ->select('guests.nationality_code', DB::raw('COUNT(*) as count'))
            ->groupBy('guests.nationality_code')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // Recent arrivals in zone (last 24h)
        $recentArrivals = CheckIn::with(['hotel.address', 'room', 'guests'])
            ->where('status', 'active')
            ->whereDate('check_in_date', '>=', now()->subDay()->toDateString())
            ->tap($scope)
            ->orderByDesc('check_in_date')
            ->limit(10)
            ->get()
            ->map(function ($ci) {
                $primary = $ci->guests->where('is_primary', true)->first() ?? $ci->guests->first();
                return [
                    'check_in_id'  => $ci->id,
                    'guest_name'   => $primary ? "{$primary->first_name} {$primary->last_name}" : '—',
                    'nationality'  => $primary?->nationality_code,
                    'hotel'        => $ci->hotel?->name,
                    'room'         => $ci->room?->number,
                    'check_in_date'=> $ci->check_in_date,
                    'guests_count' => $ci->guests->count(),
                ];
            });

        return [
            'type'              => 'police',
            'governorate'       => $governorate,
            'active_guests'     => $activeGuests,
            'check_ins_today'   => $checkInsToday,
            'check_outs_today'  => $checkOutsToday,
            'hotels_in_zone'    => $hotelsInZone,
            'expiring_docs_30d' => $expiringCount,
            'nationalities'     => $nationalities,
            'recent_arrivals'   => $recentArrivals,
        ];
    }
}
