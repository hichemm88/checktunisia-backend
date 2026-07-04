<?php

namespace Database\Factories;

use App\Models\CheckIn;
use App\Models\CheckInGuest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIn>
 */
class CheckInFactory extends Factory
{
    protected $model = CheckIn::class;

    public function definition(): array
    {
        // A creator user is needed — will use or create one
        return [
            'hotel_id'                 => Hotel::factory()->withActiveSubscription(),
            'room_id'                  => null,
            'reference'                => 'QYD-' . now()->format('Ymd') . '-' . fake()->unique()->numberBetween(1000, 9999),
            'booking_source'           => 'direct',
            'check_in_date'            => now()->toDateString(),
            'expected_check_out_date'  => now()->addDays(3)->toDateString(),
            'actual_check_out_date'    => null,
            'status'                   => 'active',
            'adults_count'             => 1,
            'children_count'           => 0,
            'notes'                    => null,
            'metadata'                 => [],
            'created_by'               => User::factory(),
            'completed_by'             => null,
            'completed_at'             => null,
        ];
    }

    // ── Status states ────────────────────────────────────────────────────────

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function completed(): static
    {
        return $this->state([
            'status'                => 'completed',
            'actual_check_out_date' => now()->toDateString(),
        ]);
    }

    /**
     * Attach a guest as primary to this check-in after creation.
     * Usage: CheckInFactory::for($hotel)->withGuest('Ahmed', 'Ben Ali')->create()
     */
    public function withGuest(string $firstName, string $lastName): static
    {
        return $this->afterCreating(function (CheckIn $checkIn) use ($firstName, $lastName) {
            $guest = Guest::factory()->named($firstName, $lastName)->create();

            CheckInGuest::create([
                'check_in_id' => $checkIn->id,
                'guest_id'    => $guest->id,
                'is_primary'  => true,
                'added_by'    => $checkIn->created_by,
            ]);
        });
    }
}
