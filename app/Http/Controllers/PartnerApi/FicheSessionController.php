<?php

namespace App\Http\Controllers\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\FicheSession;
use App\Services\PartnerApi\FicheSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FicheSessionController extends Controller
{
    public function __construct(private FicheSessionService $sessions) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'establishment_id' => ['required', 'uuid'],
            'booking_ref' => ['required', 'string', 'max:100'],
            'arrival_date' => ['required', 'date'],
            'departure_date' => ['required', 'date', 'after_or_equal:arrival_date'],
            'room' => ['nullable', 'string', 'max:100'],
            'guests' => ['present', 'array', 'max:20'],
            'guests.*.first_name' => ['nullable', 'string', 'max:100'],
            'guests.*.last_name' => ['nullable', 'string', 'max:100'],
            'guests.*.nationality' => ['nullable', 'string', 'max:3'],
            'guests.*.id_document_number' => ['nullable', 'string', 'max:100'],
            'guests.*.id_document_type' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]);

        $partner = app(ApiPartner::class);
        $apiKey = app(ApiKey::class);

        ['session' => $session, 'created' => $created] = $this->sessions->createOrReuse($partner, $apiKey, [
            'establishment_id' => $validated['establishment_id'],
            'booking_ref' => $validated['booking_ref'],
            'arrival_date' => $validated['arrival_date'],
            'departure_date' => $validated['departure_date'],
            'room' => $validated['room'] ?? null,
            'guests' => $validated['guests'],
            'metadata' => $validated['metadata'] ?? [],
        ]);

        return response()->json([
            'session_id' => $session->id,
            'widget_url' => $this->sessions->widgetUrl($session),
            'expires_at' => $session->expires_at->toIso8601String(),
            'mode' => $session->mode,
        ], $created ? 201 : 200);
    }

    public function show(string $sessionId): JsonResponse
    {
        $partner = app(ApiPartner::class);
        $session = $this->sessions->findForPartner($partner, $sessionId);

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->publicStatus(),
            'fiche_id' => $session->status === FicheSession::STATUS_SUBMITTED ? $session->check_in_id : null,
            'booking_ref' => $session->booking_reference,
            'created_at' => $session->created_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }
}
