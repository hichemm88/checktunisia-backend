<?php

namespace Database\Factories;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email'             => fake()->unique()->safeEmail(),
            'password'          => Hash::make('Password1!Test'),
            'first_name'        => fake()->firstName(),
            'last_name'         => fake()->lastName(),
            'phone'             => fake()->optional()->phoneNumber(),
            'status'            => 'active',
            'email_verified_at' => now(),
            'metadata'          => [],
        ];
    }

    // ── Role states ──────────────────────────────────────────────────────────

    /**
     * Create a hotel_admin user linked to the given hotel.
     */
    public function hotelAdmin(Hotel $hotel): static
    {
        return $this->afterCreating(function (User $user) use ($hotel) {
            $user->assignRole('hotel_admin');
            $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        });
    }

    /**
     * Create a receptionist user linked to the given hotel.
     */
    public function receptionist(Hotel $hotel): static
    {
        return $this->afterCreating(function (User $user) use ($hotel) {
            $user->assignRole('receptionist');
            $user->hotels()->attach($hotel->id, ['granted_at' => now()]);
        });
    }

    /**
     * Create an authority_user linked to the given organization, with a valid profile.
     */
    public function authorityUser(AuthorityOrganization $organization): static
    {
        return $this->afterCreating(function (User $user) use ($organization) {
            $user->assignRole('authority_user');

            AuthorityUserProfile::create([
                'user_id'         => $user->id,
                'organization_id' => $organization->id,
                'badge_number'    => fake()->numerify('TN-#####'),
                'rank'            => 'Agent',
                'authorized_at'   => now()->subMonth(),
                'expires_at'      => now()->addYear(),
            ]);
        });
    }

    /**
     * Create a platform_admin user.
     */
    public function platformAdmin(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->assignRole('platform_admin');
        });
    }
}
