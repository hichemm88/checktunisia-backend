<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Services\CheckIn\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuestController extends Controller
{
    public function __construct(private CheckInService $service) {}

    public function store(Request $request, string $checkInId): JsonResponse
    {
        $checkIn = $this->findCheckIn($checkInId);

        if ($error = $this->assertModifiable($checkIn)) {
            return $error;
        }

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
            // Champs arabes de la CIN tunisienne (préremplis par le scan).
            'last_name_ar' => ['nullable', 'string', 'max:150'],
            'first_name_ar' => ['nullable', 'string', 'max:150'],
            'filiation_ar' => ['nullable', 'string', 'max:200'],
            'spouse_ar' => ['nullable', 'string', 'max:150'],
            'birth_place_ar' => ['nullable', 'string', 'max:150'],
            'card_format' => ['nullable', 'in:legacy,biometric'],
            // MODULE PROVISOIRE — relais WhatsApp : scan à relier à ce voyageur
            // (téléversé à l'étape scan) pour joindre la bonne photo à sa fiche.
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

        $guest = $this->service->addGuest($checkIn, $request->user(), $validated);

        return response()->json(['data' => $this->format($guest, $checkIn->id)], 201);
    }

    public function update(Request $request, string $checkInId, string $guestId): JsonResponse
    {
        $checkIn = $this->findCheckIn($checkInId);

        if ($error = $this->assertModifiable($checkIn)) {
            return $error;
        }

        $guest = $this->findGuest($checkIn, $guestId);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', 'date'],
            'sex' => ['sometimes', 'in:M,F,X'],
            'nationality_code' => ['sometimes', 'string', 'size:3'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'last_name_ar' => ['nullable', 'string', 'max:150'],
            'first_name_ar' => ['nullable', 'string', 'max:150'],
            'filiation_ar' => ['nullable', 'string', 'max:200'],
            'spouse_ar' => ['nullable', 'string', 'max:150'],
            'birth_place_ar' => ['nullable', 'string', 'max:150'],
            'card_format' => ['nullable', 'in:legacy,biometric'],
            'document' => ['sometimes', 'array'],
            'document.expiry_date' => ['nullable', 'date'],
            'document.document_number' => ['sometimes', 'string'],
            'document.issuing_country_code' => ['sometimes', 'string', 'size:2'],
        ]);

        $guest = $this->service->updateGuest($checkIn, $guest, $validated);

        return response()->json(['data' => $this->format($guest, $checkIn->id)]);
    }

    public function destroy(string $checkInId, string $guestId): JsonResponse
    {
        $checkIn = $this->findCheckIn($checkInId);

        if ($error = $this->assertModifiable($checkIn)) {
            return $error;
        }

        $guest = $this->findGuest($checkIn, $guestId);

        $this->service->removeGuest($checkIn, $guest);

        return response()->json(null, 204);
    }

    /**
     * Guests may only be added/edited/removed while the check-in is still open
     * (draft or active). Completed / cancelled / no-show stays are frozen for
     * audit and police-record integrity. Returns a 409 response when frozen.
     */
    private function assertModifiable(CheckIn $checkIn): ?JsonResponse
    {
        if ($checkIn->canBeModified()) {
            return null;
        }

        return response()->json([
            'data' => null,
            'errors' => [['code' => 'CHECK_IN_NOT_MODIFIABLE', 'message' => 'Ce check-in est clôturé : la liste des voyageurs ne peut plus être modifiée.', 'field' => null]],
        ], 409);
    }

    private function findCheckIn(string $id): CheckIn
    {
        return CheckIn::where('id', $id)
            ->where('hotel_id', app('tenant')->id)
            ->firstOrFail();
    }

    private function findGuest(CheckIn $checkIn, string $guestId): Guest
    {
        return $checkIn->guests()->where('guests.id', $guestId)->firstOrFail();
    }

    private function format(Guest $guest, string $checkInId): array
    {
        $doc = $guest->documents->first();

        return [
            'id' => $guest->id,
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'date_of_birth' => $guest->date_of_birth,
            'sex' => $guest->sex,
            'nationality_code' => $guest->nationality_code,
            'is_primary' => (bool) $guest->pivot?->is_primary,
            // Champs arabes de la CIN tunisienne (null pour un passeport).
            'last_name_ar' => $guest->last_name_ar,
            'first_name_ar' => $guest->first_name_ar,
            'filiation_ar' => $guest->filiation_ar,
            'spouse_ar' => $guest->spouse_ar,
            'birth_place_ar' => $guest->birth_place_ar,
            'card_format' => $guest->card_format,
            'document' => $doc ? [
                'id' => $doc->id,
                'type' => $doc->type,
                'document_number' => $doc->document_number,
                'issuing_country_code' => $doc->issuing_country_code,
                'expiry_date' => $doc->expiry_date,
                'is_verified' => $doc->is_verified,
            ] : null,
        ];
    }
}
