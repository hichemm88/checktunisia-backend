<?php

namespace App\Services\CheckIn;

use App\Models\CheckIn;
use App\Models\CheckInGuest;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\OCR\OcrService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CheckInService
{
    public function __construct(private OcrService $ocrService) {}

    /**
     * Create a new draft check-in.
     */
    public function create(Hotel $hotel, User $creator, array $data): CheckIn
    {
        return DB::transaction(function () use ($hotel, $creator, $data) {
            $checkIn = CheckIn::create([
                'hotel_id'                => $hotel->id,
                'room_id'                 => $data['room_id'] ?? null,
                'booking_reference'       => $data['booking_reference'] ?? null,
                'booking_source'          => $data['booking_source'] ?? 'direct',
                'check_in_date'           => $data['check_in_date'],
                'expected_check_out_date' => $data['expected_check_out_date'],
                'adults_count'            => $data['adults_count'] ?? 1,
                'children_count'          => $data['children_count'] ?? 0,
                'notes'                   => $data['notes'] ?? null,
                'status'                  => 'draft',
                'created_by'              => $creator->id,
            ]);

            AuditLogger::log(
                action: 'check_in.created',
                subject: $checkIn,
                newValues: $checkIn->toArray(),
                hotelId: $hotel->id,
            );

            return $checkIn->load(['room', 'guests']);
        });
    }

    /**
     * Add a guest to an existing check-in.
     */
    public function addGuest(CheckIn $checkIn, User $addedBy, array $data): Guest
    {
        return DB::transaction(function () use ($checkIn, $addedBy, $data) {
            // Upsert guest: if same document exists, reuse the guest record
            $guest = $this->findOrCreateGuest($data);

            // Upsert travel document
            if (!empty($data['document'])) {
                $this->upsertDocument($guest, $data['document']);
            }

            // Link to check-in
            $isPrimary = $data['is_primary'] ?? ($checkIn->guests()->count() === 0);

            CheckInGuest::updateOrCreate(
                ['check_in_id' => $checkIn->id, 'guest_id' => $guest->id],
                ['is_primary' => $isPrimary, 'added_by' => $addedBy->id, 'added_at' => now()],
            );

            AuditLogger::log(
                action: 'guest.added',
                subject: $guest,
                newValues: ['check_in_id' => $checkIn->id, 'guest_id' => $guest->id],
                hotelId: $checkIn->hotel_id,
            );

            return $guest->load(['documents']);
        });
    }

    /**
     * Update a guest's data (post-OCR correction).
     */
    public function updateGuest(CheckIn $checkIn, Guest $guest, array $data): Guest
    {
        return DB::transaction(function () use ($checkIn, $guest, $data) {
            $old = $guest->toArray();
            $guest->update(array_filter([
                'first_name'      => $data['first_name'] ?? null,
                'last_name'       => $data['last_name'] ?? null,
                'date_of_birth'   => $data['date_of_birth'] ?? null,
                'sex'             => $data['sex'] ?? null,
                'nationality_code' => $data['nationality_code'] ?? null,
                'place_of_birth'  => $data['place_of_birth'] ?? null,
                'email'           => $data['email'] ?? null,
                'phone'           => $data['phone'] ?? null,
            ], fn($v) => !is_null($v)));

            if (!empty($data['document'])) {
                $doc = $guest->primaryDocument;
                if ($doc) {
                    $doc->update(array_filter($data['document'], fn($v) => !is_null($v)));
                }
            }

            AuditLogger::log('guest.updated', $guest, $old, $guest->fresh()->toArray(), hotelId: $checkIn->hotel_id);

            return $guest->load(['documents']);
        });
    }

    /**
     * Remove a guest from a check-in.
     */
    public function removeGuest(CheckIn $checkIn, Guest $guest): void
    {
        DB::transaction(function () use ($checkIn, $guest) {
            $link = CheckInGuest::where('check_in_id', $checkIn->id)
                ->where('guest_id', $guest->id)
                ->firstOrFail();

            if ($link->is_primary && $checkIn->guests()->count() === 1) {
                throw new \DomainException('Cannot remove the only primary guest.');
            }

            $link->delete();

            AuditLogger::log('guest.removed', $guest, ['check_in_id' => $checkIn->id], [], hotelId: $checkIn->hotel_id);
        });
    }

    /**
     * Complete (validate) a check-in — moves from draft → active.
     */
    public function complete(CheckIn $checkIn, User $completedBy): CheckIn
    {
        if (!$checkIn->isDraft()) {
            throw new \DomainException('Only draft check-ins can be completed.');
        }

        if ($checkIn->guests()->count() === 0) {
            throw new \DomainException('At least one guest is required before completing a check-in.');
        }

        return DB::transaction(function () use ($checkIn, $completedBy) {
            $checkIn->update([
                'status'       => 'active',
                'completed_by' => $completedBy->id,
                'completed_at' => now(),
            ]);

            AuditLogger::log('check_in.completed', $checkIn, ['status' => 'draft'], ['status' => 'active'], hotelId: $checkIn->hotel_id);

            return $checkIn->fresh()->load(['room', 'guests.documents', 'creator']);
        });
    }

    /**
     * Record actual check-out.
     */
    public function checkout(CheckIn $checkIn, string $checkOutDate): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $checkOutDate) {
            $old = ['status' => $checkIn->status, 'actual_check_out_date' => null];
            $checkIn->update([
                'status'                => 'completed',
                'actual_check_out_date' => $checkOutDate,
            ]);

            AuditLogger::log('check_in.checked_out', $checkIn, $old, $checkIn->fresh()->only(['status', 'actual_check_out_date']), hotelId: $checkIn->hotel_id);

            return $checkIn->fresh();
        });
    }

    /**
     * Cancel a check-in.
     */
    public function cancel(CheckIn $checkIn, string $reason): CheckIn
    {
        return DB::transaction(function () use ($checkIn, $reason) {
            $checkIn->update([
                'status'   => 'cancelled',
                'metadata' => array_merge($checkIn->metadata ?? [], ['cancel_reason' => $reason]),
            ]);

            AuditLogger::log('check_in.cancelled', $checkIn, hotelId: $checkIn->hotel_id);

            return $checkIn->fresh();
        });
    }

    /**
     * Upload a passport scan and trigger OCR.
     */
    public function uploadScan(CheckIn $checkIn, User $uploader, UploadedFile $file): DocumentScan
    {
        $hash    = hash_file('sha256', $file->getRealPath());
        $path    = $file->store("scans/{$checkIn->hotel_id}/{$checkIn->id}", config('filesystems.passport_scan_disk', 'local'));

        $scan = DocumentScan::create([
            'check_in_id'   => $checkIn->id,
            'file_path'     => $path,
            'file_hash'     => $hash,
            'file_size_bytes' => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'ocr_status'    => 'pending',
            'uploaded_by'   => $uploader->id,
        ]);

        AuditLogger::log('scan.uploaded', $scan, [], ['check_in_id' => $checkIn->id], hotelId: $checkIn->hotel_id);

        // Process synchronously in mock mode; dispatch job in production
        if (config('ocr.driver', 'mock') === 'mock') {
            $this->ocrService->process($scan);
        } else {
            // ProcessPassportScan::dispatch($scan);
        }

        return $scan->fresh();
    }

    // ─── Private helpers ──────────────────────────────────────────────

    private function findOrCreateGuest(array $data): Guest
    {
        // Try to find by document number to avoid duplicates
        if (!empty($data['document']['document_number'])) {
            $doc = TravelDocument::where('document_number', $data['document']['document_number'])
                ->where('type', $data['document']['type'] ?? 'passport')
                ->first();

            if ($doc) return $doc->guest;
        }

        return Guest::create([
            'first_name'      => $data['first_name'],
            'last_name'       => strtoupper($data['last_name']),
            'date_of_birth'   => $data['date_of_birth'],
            'sex'             => $data['sex'] ?? 'M',
            'nationality_code' => $data['nationality_code'],
            'country_of_birth' => $data['country_of_birth'] ?? null,
            'place_of_birth'  => $data['place_of_birth'] ?? null,
            'email'           => $data['email'] ?? null,
            'phone'           => $data['phone'] ?? null,
        ]);
    }

    private function upsertDocument(Guest $guest, array $docData): TravelDocument
    {
        return TravelDocument::updateOrCreate(
            [
                'type'                => $docData['type'] ?? 'passport',
                'document_number'     => $docData['document_number'],
                'issuing_country_code' => $docData['issuing_country_code'] ?? 'TN',
            ],
            [
                'guest_id'    => $guest->id,
                'issue_date'  => $docData['issue_date'] ?? null,
                'expiry_date' => $docData['expiry_date'] ?? null,
                'mrz_line1'   => $docData['mrz_line1'] ?? null,
                'mrz_line2'   => $docData['mrz_line2'] ?? null,
                'is_verified' => true,
            ]
        );
    }
}
