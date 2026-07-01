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

        $validated = $request->validate([
            'first_name'       => ['required', 'string', 'max:100'],
            'last_name'        => ['required', 'string', 'max:100'],
            'date_of_birth'    => ['required', 'date', 'before:today'],
            'sex'              => ['required', 'in:M,F,X'],
            'nationality_code' => ['required', 'string', 'size:3'],
            'country_of_birth' => ['nullable', 'string', 'size:3'],
            'place_of_birth'   => ['nullable', 'string', 'max:150'],
            'email'            => ['nullable', 'email'],
            'phone'            => ['nullable', 'string', 'max:30'],
            'is_primary'       => ['boolean'],
            'document'                             => ['required', 'array'],
            'document.type'                        => ['required', 'string', 'in:passport,national_id,residence_permit,visa'],
            'document.document_number'             => ['required', 'string', 'max:100'],
            'document.issuing_country_code'        => ['required', 'string', 'size:2'],
            'document.issue_date'                  => ['nullable', 'date'],
            'document.expiry_date'                 => ['nullable', 'date'],
            'document.mrz_line1'                   => ['nullable', 'string', 'max:50'],
            'document.mrz_line2'                   => ['nullable', 'string', 'max:50'],
        ]);

        $guest = $this->service->addGuest($checkIn, $request->user(), $validated);

        return response()->json(['data' => $this->format($guest, $checkIn->id)], 201);
    }

    public function update(Request $request, string $checkInId, string $guestId): JsonResponse
    {
        $checkIn = $this->findCheckIn($checkInId);
        $guest   = $this->findGuest($checkIn, $guestId);

        $validated = $request->validate([
            'first_name'       => ['sometimes', 'string', 'max:100'],
            'last_name'        => ['sometimes', 'string', 'max:100'],
            'date_of_birth'    => ['sometimes', 'date'],
            'sex'              => ['sometimes', 'in:M,F,X'],
            'nationality_code' => ['sometimes', 'string', 'size:3'],
            'place_of_birth'   => ['nullable', 'string', 'max:150'],
            'email'            => ['nullable', 'email'],
            'phone'            => ['nullable', 'string', 'max:30'],
            'document'                      => ['sometimes', 'array'],
            'document.expiry_date'          => ['nullable', 'date'],
            'document.document_number'      => ['sometimes', 'string'],
            'document.issuing_country_code' => ['sometimes', 'string', 'size:2'],
        ]);

        $guest = $this->service->updateGuest($checkIn, $guest, $validated);

        return response()->json(['data' => $this->format($guest, $checkIn->id)]);
    }

    public function destroy(string $checkInId, string $guestId): JsonResponse
    {
        $checkIn = $this->findCheckIn($checkInId);
        $guest   = $this->findGuest($checkIn, $guestId);

        $this->service->removeGuest($checkIn, $guest);

        return response()->json(null, 204);
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
            'id'               => $guest->id,
            'first_name'       => $guest->first_name,
            'last_name'        => $guest->last_name,
            'date_of_birth'    => $guest->date_of_birth,
            'sex'              => $guest->sex,
            'nationality_code' => $guest->nationality_code,
            'is_primary'       => (bool) $guest->pivot?->is_primary,
            'document'         => $doc ? [
                'id'                   => $doc->id,
                'type'                 => $doc->type,
                'document_number'      => $doc->document_number,
                'issuing_country_code' => $doc->issuing_country_code,
                'expiry_date'          => $doc->expiry_date,
                'is_verified'          => $doc->is_verified,
            ] : null,
        ];
    }
}
