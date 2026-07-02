<?php

namespace Database\Factories;

use App\Models\Hotel;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Hotel>
 */
class HotelFactory extends Factory
{
    protected $model = Hotel::class;

    public function definition(): array
    {
        $name = fake()->company() . ' Hotel';
        return [
            'name'                => $name,
            'slug'                => Str::slug($name) . '-' . fake()->unique()->numberBetween(1000, 9999),
            'type'                => 'hotel',
            'registration_number' => null,
            'stars'               => fake()->numberBetween(1, 5),
            'room_count'          => fake()->numberBetween(10, 100),
            'status'              => 'active',
            'metadata'            => [],
        ];
    }

    /**
     * Create a hotel with an active subscription (required for tenant middleware).
     */
    public function withActiveSubscription(): static
    {
        return $this->afterCreating(function (Hotel $hotel) {
            $plan = SubscriptionPlan::firstOrCreate(
                ['slug' => 'standard'],
                [
                    'name'          => 'Standard',
                    'min_rooms'     => 1,
                    'max_rooms'     => null,
                    'price_monthly' => 99.000,
                    'currency'      => 'TND',
                    'features'      => [],
                    'is_active'     => true,
                    'sort_order'    => 1,
                ]
            );

            Subscription::create([
                'hotel_id'      => $hotel->id,
                'plan_id'       => $plan->id,
                'status'        => 'active',
                'billing_cycle' => 'monthly',
                'started_at'    => now()->subMonth(),
                'expires_at'    => now()->addMonth(),
                'auto_renew'    => true,
                'metadata'      => [],
            ]);
        });
    }

    /**
     * Set the primary address governorate for the hotel.
     */
    public function inGovernorate(string $governorate): static
    {
        return $this->afterCreating(function (Hotel $hotel) use ($governorate) {
            $hotel->addresses()->create([
                'line1'       => fake()->streetAddress(),
                'city'        => $governorate,
                'governorate' => $governorate,
                'country_code' => 'TN',
                'is_primary'  => true,
            ]);
        });
    }

    /**
     * Hotel with pending status (no active subscription).
     */
    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    /**
     * Suspended hotel.
     */
    public function suspended(): static
    {
        return $this->state(['status' => 'suspended']);
    }
}
