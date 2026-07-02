<?php

namespace Database\Factories;

use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'first_name'      => fake()->firstName(),
            'last_name'       => fake()->lastName(),
            'date_of_birth'   => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'sex'             => fake()->randomElement(['M', 'F']),
            'nationality_code' => 'TUN',
            'country_of_birth' => 'TUN',
            'place_of_birth'   => fake()->city(),
            'email'            => fake()->optional()->safeEmail(),
            'phone'            => fake()->optional()->phoneNumber(),
            'address'          => null,
            'metadata'         => [],
        ];
    }

    /**
     * Guest with a specific first and last name (for search/watchlist tests).
     */
    public function named(string $firstName, string $lastName): static
    {
        return $this->state([
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);
    }
}
