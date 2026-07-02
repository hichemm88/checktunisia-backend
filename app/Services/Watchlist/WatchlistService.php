<?php

namespace App\Services\Watchlist;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;

class WatchlistService
{
    /**
     * Check if a guest matches any active watchlist entry.
     * Matching priority:
     *   1. Exact document number match  (hit_type = 'document')
     *   2. Last name + date of birth    (hit_type = 'name_dob')
     *
     * Returns the highest-severity match payload, or null.
     */
    public function checkGuest(Guest $guest): ?array
    {
        $docNumbers = $guest->documents->pluck('document_number')->filter()->values()->toArray();

        $matches = WatchlistEntry::active()
            ->with(['organization'])
            ->where(function ($q) use ($guest, $docNumbers) {
                // 1. Exact document match
                if (!empty($docNumbers)) {
                    $q->orWhereIn('document_number', $docNumbers);
                }
                // 2. Last name + date of birth
                if ($guest->last_name && $guest->date_of_birth) {
                    $q->orWhere(function ($q2) use ($guest) {
                        $q2->whereRaw('LOWER(last_name) = LOWER(?)', [$guest->last_name])
                            ->whereDate('date_of_birth', $guest->date_of_birth)
                            ->whereNotNull('date_of_birth');
                    });
                }
            })
            ->orderByRaw("CASE severity WHEN 'critique' THEN 1 WHEN 'eleve' THEN 2 ELSE 3 END")
            ->get();

        if ($matches->isEmpty()) {
            return null;
        }

        $top       = $matches->first();
        $docMatch  = !empty($docNumbers) && in_array($top->document_number, $docNumbers);

        return [
            'entry_id'   => $top->id,
            'severity'   => $top->severity,
            'reason_code'=> $top->reason_code,
            'reason'     => $top->reason,           // caller decides whether to expose this
            'hit_type'   => $docMatch ? 'document' : 'name_dob',
            'org_name'   => $top->organization?->name,
        ];
    }

    /**
     * Called on check-in completion.
     * Checks all guests and records any hits → notifies the hotel.
     */
    public function checkCheckIn(CheckIn $checkIn): int
    {
        $hitCount = 0;

        foreach ($checkIn->guests as $guest) {
            $match = $this->checkGuest($guest->load('documents'));
            if (!$match) {
                continue;
            }

            WatchlistHit::updateOrCreate(
                [
                    'watchlist_entry_id' => $match['entry_id'],
                    'guest_id'           => $guest->id,
                    'check_in_id'        => $checkIn->id,
                ],
                [
                    'hotel_id'           => $checkIn->hotel_id,
                    'hit_type'           => $match['hit_type'],
                    'notified_hotel_at'  => now(),
                ]
            );

            $hitCount++;
        }

        return $hitCount;
    }

    /**
     * Count unacknowledged hits for a hotel (for dashboard badge).
     */
    public function pendingHitsForHotel(string $hotelId): int
    {
        return WatchlistHit::where('hotel_id', $hotelId)
            ->whereNull('acknowledged_at')
            ->count();
    }
}
