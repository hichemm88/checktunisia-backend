<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthoritySearchController extends Controller
{
    /**
     * GET /authority/search
     * Cross-tenant guest search — every call is logged.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q'             => ['nullable', 'string', 'min:2', 'max:100'],
            'passport_number' => ['nullable', 'string', 'max:100'],
            'nationality'   => ['nullable', 'string', 'size:3'],
            'date_of_birth' => ['nullable', 'date'],
            'stay_from'     => ['nullable', 'date'],
            'stay_to'       => ['nullable', 'date'],
            'hotel_id'      => ['nullable', 'uuid'],
        ]);

        // Require at least one search parameter
        if (!$request->hasAny(['q', 'passport_number', 'nationality', 'date_of_birth', 'stay_from', 'stay_to', 'hotel_id'])) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'At least one search parameter is required.', 'field' => null]],
            ], 422);
        }

        $start = microtime(true);

        $query = Guest::with(['primaryDocument', 'checkIns.hotel'])
            ->select('guests.*');

        // Full-text search on name
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%");
            });
        }

        // Exact passport number match
        if ($request->filled('passport_number')) {
            $query->whereHas('documents', fn($d) =>
                $d->where('document_number', $request->passport_number)
            );
        }

        // Nationality filter
        if ($request->filled('nationality')) {
            $query->where('nationality_code', strtoupper($request->nationality));
        }

        // Date of birth filter
        if ($request->filled('date_of_birth')) {
            $query->whereDate('date_of_birth', $request->date_of_birth);
        }

        // Stay period filter
        if ($request->filled('stay_from') || $request->filled('stay_to')) {
            $query->whereHas('checkIns', function ($ci) use ($request) {
                if ($request->filled('stay_from')) {
                    $ci->where('expected_check_out_date', '>=', $request->stay_from);
                }
                if ($request->filled('stay_to')) {
                    $ci->where('check_in_date', '<=', $request->stay_to);
                }
            });
        }

        // Hotel filter
        if ($request->filled('hotel_id')) {
            $query->whereHas('checkIns', fn($ci) => $ci->where('hotel_id', $request->hotel_id));
        }

        $results = $query->paginate($request->integer('per_page', 20));

        $executionMs = (int) ((microtime(true) - $start) * 1000);

        // Log the search
        AuditLogger::logAuthoritySearch(
            searchParams: $request->only(['q', 'passport_number', 'nationality', 'date_of_birth', 'stay_from', 'stay_to', 'hotel_id']),
            resultCount: $results->total(),
            executionTimeMs: $executionMs,
        );

        return response()->json([
            'data' => $results->map(fn(Guest $g) => $this->summarize($g)),
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
            $q->with('hotel.address')->orderByDesc('check_in_date');
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
                    'type'                 => $d->type,
                    'document_number'      => $d->document_number,
                    'issuing_country_code' => $d->issuing_country_code,
                    'issue_date'           => $d->issue_date,
                    'expiry_date'          => $d->expiry_date,
                ]),
                'stays' => $guest->checkIns->map(fn(CheckIn $c) => [
                    'check_in_id'           => $c->id,
                    'hotel'                 => [
                        'name'                => $c->hotel->name,
                        'city'                => $c->hotel->address?->city,
                        'governorate'         => $c->hotel->address?->governorate,
                        'registration_number' => $c->hotel->registration_number,
                    ],
                    'room_number'           => $c->room?->number,
                    'check_in_date'         => $c->check_in_date,
                    'expected_check_out_date' => $c->expected_check_out_date,
                    'actual_check_out_date' => $c->actual_check_out_date,
                    'status'                => $c->status,
                ]),
            ],
        ]);
    }

    /**
     * GET /authority/hotels
     */
    public function hotels(Request $request): JsonResponse
    {
        $query = Hotel::with('address')->where('status', 'active');

        if ($request->filled('search')) {
            $query->where('name', 'ilike', "%{$request->search}%");
        }
        if ($request->filled('governorate')) {
            $query->whereHas('address', fn($a) => $a->where('governorate', 'ilike', "%{$request->governorate}%"));
        }

        $hotels = $query->orderBy('name')->paginate(20);

        return response()->json([
            'data' => $hotels->map(fn(Hotel $h) => [
                'id'   => $h->id,
                'name' => $h->name,
                'type' => $h->type,
                'stars' => $h->stars,
                'room_count' => $h->room_count,
                'registration_number' => $h->registration_number,
                'status' => $h->status,
                'address' => $h->address ? [
                    'city'        => $h->address->city,
                    'governorate' => $h->address->governorate,
                ] : null,
            ]),
            'meta' => ['total' => $hotels->total()],
        ]);
    }

    /**
     * GET /authority/hotels/{id}
     */
    public function showHotel(string $id): JsonResponse
    {
        $hotel = Hotel::with(['address', 'contacts'])->findOrFail($id);

        AuditLogger::log('authority.hotel_viewed', $hotel);

        return response()->json([
            'data' => [
                'id'                  => $hotel->id,
                'name'                => $hotel->name,
                'type'                => $hotel->type,
                'stars'               => $hotel->stars,
                'room_count'          => $hotel->room_count,
                'registration_number' => $hotel->registration_number,
                'status'              => $hotel->status,
                'address'             => $hotel->address,
                'contacts'            => $hotel->contacts->where('is_primary', true)->values(),
            ],
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────

    private function summarize(Guest $g): array
    {
        $doc       = $g->primaryDocument;
        $lastStay  = $g->checkIns->sortByDesc('check_in_date')->first();

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
        ];
    }
}
