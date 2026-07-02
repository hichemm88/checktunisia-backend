<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'hotel_id' => null, // must be set via for() or hotel_id
            'number'   => fake()->unique()->numerify('###'),
            'floor'    => fake()->numberBetween(1, 10),
            'type'     => fake()->randomElement(['standard', 'suite', 'apartment']),
            'capacity' => 2,
            'status'   => 'available',
            'metadata' => [],
        ];
    }

    public function occupied(): static
    {
        return $this->state(['status' => 'occupied']);
    }

    public function maintenance(): static
    {
        return $this->state(['status' => 'maintenance']);
    }
}
