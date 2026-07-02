<?php

namespace Database\Factories;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchlistHit>
 */
class WatchlistHitFactory extends Factory
{
    protected $model = WatchlistHit::class;

    public function definition(): array
    {
        return [
            'watchlist_entry_id' => WatchlistEntry::factory(),
            'guest_id'           => Guest::factory(),
            'check_in_id'        => CheckIn::factory(),
            'hotel_id'           => Hotel::factory()->withActiveSubscription(),
            'hit_type'           => 'document',
            'notified_hotel_at'  => now(),
            'acknowledged_at'    => null,
            'acknowledged_by'    => null,
        ];
    }

    public function acknowledged(): static
    {
        return $this->state([
            'acknowledged_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state([
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ]);
    }
}
