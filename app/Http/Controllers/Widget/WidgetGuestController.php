<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\FicheSession;
use App\Services\PartnerApi\WidgetSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ajout/retrait de voyageurs dans le widget — mêmes règles de validation que
 * GuestController (flux natif), voir WidgetSubmissionService::addGuest qui
 * délègue à CheckInService::addGuest sans rien dupliquer.
 */
class WidgetGuestController extends Controller
{
    public function __construct(private WidgetSubmissionService $submissions) {}

    public function store(Request $request): JsonResponse
    {
        $session = app(FicheSession::class);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', 'in:M,F,X'],
            'nationality_code' => ['required', 'string', 'size:3'],
            'country_of_birth' => ['nullable', 'string', 'size:3'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['boolean'],
            'scan_id' => ['nullable', 'uuid'],
            'document' => ['required', 'array'],
            'document.type' => ['required', 'string', 'in:passport,national_id,residence_permit,visa,travel_document'],
            'document.document_number' => ['required', 'string', 'max:100'],
            'document.issuing_country_code' => ['required', 'string', 'min:2', 'max:3'],
            'document.issue_date' => ['nullable', 'date'],
            'document.expiry_date' => ['nullable', 'date'],
            'document.mrz_line1' => ['nullable', 'string', 'max:50'],
            'document.mrz_line2' => ['nullable', 'string', 'max:50'],
        ]);

        $guest = $this->submissions->addGuest($session, $validated);

        return response()->json(['data' => [
            'id' => $guest->id,
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'nationality_code' => $guest->nationality_code,
        ]], 201);
    }

    public function destroy(string $guestId): JsonResponse
    {
        $session = app(FicheSession::class);

        $this->submissions->removeGuest($session, $guestId);

        return response()->json(null, 204);
    }
}
