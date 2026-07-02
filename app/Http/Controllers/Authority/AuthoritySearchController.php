<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Services\Audit\AuditLogger;
use App\Services\Watchlist\WatchlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthoritySearchController extends Controller
{
    public function __construct(private WatchlistService $watchlist) {}

    /** Resolve org type + governorate for the authenticated authority user. */
    private function authorityProfile(Request $request): array
    {
        $profile = $request->user()->authorityProfile()->with('organization')->first();
        return [
            'org_type'    => $profile?->organization?->type,
            'governorate' => $profile?->organization?->governorate,
        ];
    }

    /**
     * GET /authority/search
     * Cross-tenant guest search — every call is logged.
     * Police users are auto-scoped to their governorate.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'first_name'       => ['nullable', 'string', 'min:2', 'max:100'],
            'last_name'        => ['nullable', 'string', 'min:2', 'max:100'],
            'document_number'  => ['nullable', 'string', 'max:100'],
            'nationality_code' => ['nullable', 'string', 'min:2', 'max:3'],
            'date_of_birth'    => ['nullable', 'date'],
            'check_in_from'    => ['nullable', 'date'],
            'check_in_to'      => ['nullable', 'date'],
            'hotel_governorate'=> ['nullable', 'string', 'max:100'],
            'per_page'         => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Police are auto-scoped to their governorate unless overridden
        $profile = $this->authorityProfile($request);
        if (($profile['org_type'] ?? null) === 'police' && $profile['governorate'] && !$request->filled('hotel_governorate')) {
            $request->merge(['hotel_governorate' => $profile['governorate']]);
        }

        $searchableParams = collect($request->only([
            'first_name','last_name','document_number','nationality_code',
            'date_of_birth','check_in_from','check_in_to','hotel_governorate',
        ]))->filter()->isNotEmpty();

        if (!$searchableParams) {
            return response()->json([
                'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'Au moins un critère de recherche est requis.', 'field' => null]],
            ], 422);
        }

        $start = microtime(true);

        $query = Guest::with(['primaryDocument', 'checkIns.hotel'])->select('guests.*');

        if ($request->filled('first_name')) {
            $query->where('first_name', 'ilike', "%{$request->first_name}%");
        }
        if ($request->filled('last_name')) {
            $query->where('last_name', 'ilike', "%{$request->last_name}%");
        }
        if ($request->filled('document_number')) {
            $query->whereHas('documents', fn($d) =>
                $d->where('document_number', 'ilike', "%{$request->document_number}%")
            );
        }
        if ($request->filled('nationality_code')) {
            $query->where('nationality_code', strtoupper($request->nationality_code));
        }
        if ($request->filled('date_of_birth')) {
            $query->whereDate('date_of_birth', $request->date_of_birth);
        }
        if ($request->filled('check_in_from') || $request->filled('check_in_to')) {
            $query->whereHas('checkIns', function ($ci) use ($request) {
                if ($request->filled('check_in_from')) {
                    $ci->where('expected_check_out_date', '>=', $request->check_in_from);
                }
                if ($request->filled('check_in_to')) {
                    $ci->where('check_in_date', '<=', $request->check_in_to);
                }
            });
        }
        if ($request->filled('hotel_governorate')) {
            $query->whereHas('checkIns.hotel.address', fn($a) =>
                $a->where('governorate', 'ilike', "%{$request->hotel_governorate}%")
            );
        }

        $results     = $query->orderBy('last_name')->paginate($request->integer('per_page', 20));
        $executionMs = (int) ((microtime(true) - $start) * 1000);

        AuditLogger::logAuthoritySearch(
            searchParams: $request->only([
                'first_name','last_name','document_number','nationality_code',
                'date_of_birth','check_in_from','check_in_to','hotel_governorate',
            ]),
            resultCount: $results->total(),
            executionTimeMs: $executionMs,
        );

        $isMinistry = ($this->authorityProfile($request)['org_type'] === 'ministry');

        return response()->json([
            'data' => $results->map(fn(Guest $g) => $this->summarize($g, $isMinistry)),
            'meta' => [
                'total'         => $results->total(),
                'current_page'  => $results->currentPage(),
                'per_page'      => $results->perPage(),
                'search_logged' => true,
            ],
        ]);
    }

    /**
     * GET /authority/guests/{id}
     * Full guest profile — every view is logged.
     */
    public function show(string $id): JsonResponse
    {
        $guest = Guest::with(['documents', 'checkIns' => function ($q) {
            $q->with('hotel.address', 'room')->orderByDesc('check_in_date');
        }])->findOrFail($id);

        AuditLogger::logAuthorityView($guest->id);

        return response()->json([
            'data' => [
                'id'               => $guest->id,
                'first_name'       => $guest->first_name,
                'last_name'        => $guest->last_name,
                'date_of_birth'    => $guest->date_of_birth,
                'sex'              => $guest->sex,
                'nationality_code' => $guest->nationality_code,
                'documents'        => $guest->documents->map(fn($d) => [
                    'id'                   => $d->id,
                    'type'                 => $d->type,
                    'document_number'      => $d->document_number,
                    'issuing_country_code' => $d->issuing_country_code,
                    'issue_date'           => $d->issue_date,
                    'expiry_date'          => $d->expiry_date,
                    'is_verified'          => (bool) $d->is_verified,
                ]),
                'stays' => $guest->checkIns->map(fn(CheckIn $c) => [
                    'check_in_id'             => $c->id,
                    'hotel'                   => [
                        'name'                => $c->hotel?->name,
                        'city'                => $c->hotel?->address?->city,
                        'governorate'         => $c->hotel?->address?->governorate,
                        'registration_number' => $c->hotel?->registration_number,
                    ],
                    'room_number'             => $c->room?->number,
                    'check_in_date'           => $c->check_in_date,
                    'expected_check_out_date' => $c->expected_check_out_date,
                    'actual_check_out_date'   => $c->actual_check_out_date,
                    'status'                  => $c->status,
                ]),
            ],
        ]);
    }

    /**
     * GET /authority/hotels
     * Police are auto-scoped to their governorate.
     */
    public function hotels(Request $request): JsonResponse
    {
        // Auto-scope for police
        $profile = $this->authorityProfile($request);
        if (($profile['org_type'] ?? null) === 'police' && $profile['governorate'] && !$request->filled('governorate')) {
            $request->merge(['governorate' => $profile['governorate']]);
        }

        $query = Hotel::with('address');

        if ($request->filled('search')) {
            $query->where('name', 'ilike', "%{$request->search}%");
        }
        if ($request->filled('governorate')) {
            $query->whereHas('address', fn($a) =>
                $a->where('governorate', 'ilike', "%{$request->governorate}%")
            );
        }

        $hotels = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $hotels->map(fn(Hotel $h) => $this->summarizeHotel($h)),
            'meta' => [
                'total'        => $hotels->total(),
                'current_page' => $hotels->currentPage(),
                'per_page'     => $hotels->perPage(),
            ],
        ]);
    }

    /**
     * GET /authority/hotels/{id}
     */
    public function showHotel(string $id): JsonResponse
    {
        $hotel = Hotel::with(['address'])->findOrFail($id);

        AuditLogger::log('authority.hotel_viewed', $hotel);

        $summary = $this->summarizeHotel($hotel);

        // Add full address string for detail page
        $addressParts = array_filter([
            $hotel->address?->street,
            $hotel->address?->city,
            $hotel->address?->governorate,
        ]);
        $summary['address'] = implode(', ', $addressParts) ?: null;

        return response()->json(['data' => $summary]);
    }

    // ─── Private ─────────────────────────────────────────────────────

    private function summarize(Guest $g, bool $isMinistry = false): array
    {
        $doc           = $g->primaryDocument;
        $lastStay      = $g->checkIns->sortByDesc('check_in_date')->first();
        $watchlistHit  = $this->watchlist->checkGuest($g);

        return [
            'guest_id'         => $g->id,
            'first_name'       => $g->first_name,
            'last_name'        => $g->last_name,
            'date_of_birth'    => $g->date_of_birth,
            'sex'              => $g->sex,
            'nationality_code' => $g->nationality_code,
            'document_number'  => $doc?->document_number,
            'document_type'    => $doc?->type,
            'last_stay'        => $lastStay ? [
                'hotel_name'    => $lastStay->hotel?->name,
                'check_in_date' => $lastStay->check_in_date,
                'status'        => $lastStay->status,
            ] : null,
            'watchlist_hit'    => $watchlistHit ? [
                'severity'    => $watchlistHit['severity'],
                'reason_code' => $watchlistHit['reason_code'],
                'hit_type'    => $watchlistHit['hit_type'],
                // reason (full text) only exposed to ministry users
                'reason'      => $isMinistry ? $watchlistHit['reason'] : null,
            ] : null,
        ];
    }

    private function summarizeHotel(Hotel $h): array
    {
        $sub             = $h->activeSubscription;
        $activeGuests    = CheckIn::where('hotel_id', $h->id)->where('status', 'active')->count();
        $totalCheckIns   = CheckIn::where('hotel_id', $h->id)->count();

        return [
            'id'                     => $h->id,
            'name'                   => $h->name,
            'type'                   => $h->type,
            'stars'                  => $h->stars,
            'room_count'             => $h->room_count,
            'registration_number'    => $h->registration_number,
            'status'                 => $h->status,
            'subscription_status'    => $sub?->status,
            'subscription_expires_at'=> $sub?->expires_at,
            'city'                   => $h->address?->city,
            'governorate'            => $h->address?->governorate,
            'active_guests_count'    => $activeGuests,
            'total_check_ins'        => $totalCheckIns,
        ];
    }
}
