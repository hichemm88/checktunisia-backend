<?php

namespace App\Services\Watchlist;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use Illuminate\Support\Collection;

class WatchlistService
{
    /**
     * Check a single guest against the watchlist.
     * Used during check-in completion (single-guest context).
     */
    public function checkGuest(Guest $guest): ?array
    {
        $docNumbers = $guest->documents->pluck('document_number')->filter()->values()->toArray();

        $matches = WatchlistEntry::active()
            ->with(['organization'])
            ->where(function ($q) use ($guest, $docNumbers) {
                if (!empty($docNumbers)) {
                    $q->orWhereIn('document_number', $docNumbers);
                }
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

        $top      = $matches->first();
        $docMatch = !empty($docNumbers) && in_array($top->document_number, $docNumbers);

        return [
            'entry_id'    => $top->id,
            'severity'    => $top->severity,
            'reason_code' => $top->reason_code,
            'reason'      => $top->reason,
            'hit_type'    => $docMatch ? 'document' : 'name_dob',
            'org_name'    => $top->organization?->name,
        ];
    }

    /**
     * Batch-check a collection of guests against the watchlist.
     * Uses a SINGLE query to load all relevant entries, then matches in PHP.
     * Returns [guest_id => match_payload|null].
     *
     * This replaces the N+1 pattern in AuthoritySearchController.
     */
    public function batchCheckGuests(Collection $guests): array
    {
        if ($guests->isEmpty()) {
            return [];
        }

        // Collect all doc numbers and last names from the guest set
        $allDocNumbers = $guests
            ->flatMap(fn($g) => $g->documents->pluck('document_number'))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $lastNames = $guests
            ->filter(fn($g) => $g->last_name && $g->date_of_birth)
            ->pluck('last_name')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($allDocNumbers) && empty($lastNames)) {
            return $guests->mapWithKeys(fn($g) => [$g->id => null])->toArray();
        }

        // Single query: fetch all potentially matching entries
        $entries = WatchlistEntry::active()
            ->with(['organization'])
            ->where(function ($q) use ($allDocNumbers, $lastNames) {
                if (!empty($allDocNumbers)) {
                    $q->orWhereIn('document_number', $allDocNumbers);
                }
                if (!empty($lastNames)) {
                    $q->orWhereIn(\Illuminate\Support\Facades\DB::raw('LOWER(last_name)'), array_map('strtolower', $lastNames));
                }
            })
            ->orderByRaw("CASE severity WHEN 'critique' THEN 1 WHEN 'eleve' THEN 2 ELSE 3 END")
            ->get();

        if ($entries->isEmpty()) {
            return $guests->mapWithKeys(fn($g) => [$g->id => null])->toArray();
        }

        // Match each guest against the loaded entries in PHP
        $result = [];
        foreach ($guests as $guest) {
            $guestDocs    = $guest->documents->pluck('document_number')->filter()->values()->toArray();
            $guestDob     = $guest->date_of_birth ? (string) $guest->date_of_birth : null;
            $guestLastLow = $guest->last_name ? strtolower($guest->last_name) : null;

            $match = null;
            foreach ($entries as $entry) {
                // Priority 1: document match
                if ($entry->document_number && in_array($entry->document_number, $guestDocs)) {
                    $match = $this->entryToPayload($entry, 'document');
                    break;
                }
                // Priority 2: last_name + DOB match
                if (
                    $entry->last_name && $guestLastLow && strtolower($entry->last_name) === $guestLastLow
                    && $entry->date_of_birth && $guestDob
                    && (string) $entry->date_of_birth->toDateString() === $guestDob
                ) {
                    $match = $this->entryToPayload($entry, 'name_dob');
                    break;
                }
            }
            $result[$guest->id] = $match;
        }

        return $result;
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
                    'hotel_id'          => $checkIn->hotel_id,
                    'hit_type'          => $match['hit_type'],
                    'notified_hotel_at' => now(),
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

    private function entryToPayload(WatchlistEntry $entry, string $hitType): array
    {
        return [
            'entry_id'    => $entry->id,
            'severity'    => $entry->severity,
            'reason_code' => $entry->reason_code,
            'reason'      => $entry->reason,
            'hit_type'    => $hitType,
            'org_name'    => $entry->organization?->name,
        ];
    }
}
