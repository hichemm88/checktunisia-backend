<?php

namespace Database\Factories;

use App\Models\AuthorityOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorityOrganization>
 */
class AuthorityOrganizationFactory extends Factory
{
    protected $model = AuthorityOrganization::class;

    public function definition(): array
    {
        return [
            'name'        => fake()->company(),
            'type'        => 'police',
            'code'        => strtoupper(fake()->unique()->lexify('???-####')),
            'governorate' => null,
            'description' => null,
            'is_active'   => true,
            'metadata'    => [],
        ];
    }

    /**
     * Ministry of Interior — national level, no governorate scope.
     */
    public function ministry(): static
    {
        return $this->state([
            'name'        => 'Ministère de l\'Intérieur',
            'type'        => 'ministry',
            'governorate' => null,
        ]);
    }

    /**
     * Police station scoped to a governorate.
     */
    public function police(string $governorate = 'Tunis'): static
    {
        return $this->state([
            'name'        => "Brigade de Police — {$governorate}",
            'type'        => 'police',
            'governorate' => $governorate,
        ]);
    }
}
