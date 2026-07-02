<?php

namespace Database\Factories;

use App\Models\AuthorityOrganization;
use App\Models\User;
use App\Models\WatchlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchlistEntry>
 */
class WatchlistEntryFactory extends Factory
{
    protected $model = WatchlistEntry::class;

    public function definition(): array
    {
        return [
            'organization_id' => AuthorityOrganization::factory(),
            'added_by'        => User::factory(),
            'document_number' => strtoupper(fake()->bothify('??#######')),
            'document_type'   => 'passport',
            'first_name'      => fake()->firstName(),
            'last_name'       => fake()->lastName(),
            'date_of_birth'   => fake()->dateTimeBetween('-60 years', '-20 years')->format('Y-m-d'),
            'nationality_code' => 'TUN',
            'severity'        => fake()->randomElement(['moyen', 'eleve', 'critique']),
            'reason'          => fake()->sentence(),
            'reason_code'     => fake()->randomElement(['MANDAT_ARRET', 'FRAUDE', 'MIGRATION', 'AUTRE']),
            'status'          => 'active',
            'expires_at'      => null,
            'source'          => 'manual',
            'import_batch_id' => null,
        ];
    }

    public function critique(): static
    {
        return $this->state(['severity' => 'critique']);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }

    /**
     * Shorthand: WatchlistEntryFactory::for($organization)->create()
     * Uses the Eloquent for() method which sets organization_id automatically.
     * Alias here sets added_by as a user from the same org if needed.
     */
    public function addedBy(User $user): static
    {
        return $this->state(['added_by' => $user->id]);
    }
}
