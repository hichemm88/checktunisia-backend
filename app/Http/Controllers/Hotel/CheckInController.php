<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Services\CheckIn\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = app('tenant');

        $query = CheckIn::with(['room', 'creator'])
            ->where('hotel_id', $hotel->id)
            ->withCount('guests');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('check_in_date', $request->date);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('check_in_date', [$request->date_from, $request->date_to]);
        }

        if ($request->filled('room_number')) {
            $query->whereHas('room', fn($q) => $q->where('number', $request->room_number));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('guests', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%");
            });
        }

        $results = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $results->map(fn(CheckIn $c) => $this->summarize($c)),
            'meta' => [
                'total'        => $results->total(),
                'current_page' => $results->currentPage(),
                'per_page'     => $results->perPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id'                 => ['nullable', 'uuid', 'exists:rooms,id'],
            'check_in_date'           => ['required', 'date'],
            'expected_check_out_date' => ['required', 'date', 'after:check_in_date'],
            'booking_reference'       => ['nullable', 'string', 'max:100'],
            'booking_source'          => ['nullable', 'string', 'in:direct,booking,airbnb,expedia,phone,other'],
            'adults_count'            => ['integer', 'min:1', 'max:50'],
            'children_count'          => ['integer', 'min:0', 'max:20'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var Hotel $hotel */
        $hotel   = app('tenant');
        $checkIn = $this->service->create($hotel, $request->user(), $validated);

        return response()->json(['data' => $this->detail($checkIn)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);
        return response()->json(['data' => $this->detail($checkIn->load(['room', 'guests.documents', 'creator', 'completedBy']))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        if (!$checkIn->canBeModified()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'CHECK_IN_ALREADY_COMPLETED', 'message' => 'Completed check-ins cannot be modified.', 'field' => null]],
            ], 409);
        }

        $validated = $request->validate([
            'room_id'                 => ['nullable', 'uuid', 'exists:rooms,id'],
            'expected_check_out_date' => ['sometimes', 'date'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
            'adults_count'            => ['sometimes', 'integer', 'min:1'],
            'children_count'          => ['sometimes', 'integer', 'min:0'],
        ]);

        $old = $checkIn->toArray();
        $checkIn->update($validated);
        \App\Services\Audit\AuditLogger::log('check_in.updated', $checkIn, $old, $checkIn->fresh()->toArray(), hotelId: $checkIn->hotel_id);

        return response()->json(['data' => $this->detail($checkIn->fresh()->load(['room', 'guests.documents']))]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        try {
            $result = $this->service->complete($checkIn, $request->user());
        } catch (\DomainException $e) {
            return response()->json([
                'errors' => [['code' => 'INVALID_STATUS', 'message' => $e->getMessage()]],
            ], 422);
        }

        return response()->json(['data' => [
            'id'           => $result->id,
            'reference'    => $result->reference,
            'status'       => $result->status,
            'completed_at' => $result->completed_at,
        ]]);
    }

    public function checkout(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        $validated = $request->validate([
            'actual_check_out_date' => ['required', 'date', 'after_or_equal:' . $checkIn->check_in_date],
        ]);

        $result = $this->service->checkout($checkIn, $validated['actual_check_out_date']);

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status, 'actual_check_out_date' => $result->actual_check_out_date]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->service->cancel($checkIn, $validated['reason']);
        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status]]);
    }

    public function destroy(string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        if ($checkIn->status !== 'draft') {
            return response()->json([
                'errors' => [['code' => 'FORBIDDEN', 'message' => 'Seuls les brouillons peuvent être supprimés.']],
            ], 403);
        }

        $checkIn->guests()->delete();
        $checkIn->delete();

        return response()->json(null, 204);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function findForTenant(string $id): CheckIn
    {
        return CheckIn::where('id', $id)
            ->where('hotel_id', app('tenant')->id)
            ->firstOrFail();
    }

    private function summarize(CheckIn $c): array
    {
        $primary = $c->guests->first();
        return [
            'id'                      => $c->id,
            'reference'               => $c->reference,
            'room'                    => $c->room ? ['id' => $c->room->id, 'number' => $c->room->number] : null,
            'check_in_date'           => $c->check_in_date,
            'expected_check_out_date' => $c->expected_check_out_date,
            'status'                  => $c->status,
            'guests_count'            => $c->guests_count,
            'primary_guest'           => $primary ? [
                'first_name'      => $primary->first_name,
                'last_name'       => $primary->last_name,
                'nationality_code' => $primary->nationality_code,
            ] : null,
            'created_at' => $c->created_at,
        ];
    }

    private function detail(CheckIn $c): array
    {
        return [
            'id'                      => $c->id,
            'reference'               => $c->reference,
            'room'                    => $c->room ? ['id' => $c->room->id, 'number' => $c->room->number, 'floor' => $c->room->floor, 'type' => $c->room->type] : null,
            'booking_reference'       => $c->booking_reference,
            'booking_source'          => $c->booking_source,
            'check_in_date'           => $c->check_in_date,
            'expected_check_out_date' => $c->expected_check_out_date,
            'actual_check_out_date'   => $c->actual_check_out_date,
            'status'                  => $c->status,
            'adults_count'            => $c->adults_count,
            'children_count'          => $c->children_count,
            'notes'                   => $c->notes,
            'guests'                  => $c->guests->map(fn($g) => $this->formatGuest($g, $c->id)),
            'created_by'              => $c->creator ? ['id' => $c->creator->id, 'first_name' => $c->creator->first_name, 'last_name' => $c->creator->last_name] : null,
            'completed_by'            => $c->completedBy ? ['id' => $c->completedBy->id, 'first_name' => $c->completedBy->first_name] : null,
            'completed_at'            => $c->completed_at,
            'created_at'              => $c->created_at,
        ];
    }

    private function formatGuest($guest, string $checkInId): array
    {
        $doc = $guest->documents->first();
        return [
            'id'              => $guest->id,
            'first_name'      => $guest->first_name,
            'last_name'       => $guest->last_name,
            'date_of_birth'   => $guest->date_of_birth,
            'sex'             => $guest->sex,
            'nationality_code' => $guest->nationality_code,
            'is_primary'      => (bool) $guest->pivot?->is_primary,
            'document'        => $doc ? [
                'id'                  => $doc->id,
                'type'                => $doc->type,
                'document_number'     => $doc->document_number,
                'issuing_country_code' => $doc->issuing_country_code,
                'expiry_date'         => $doc->expiry_date,
                'is_verified'         => $doc->is_verified,
            ] : null,
        ];
    }
}
