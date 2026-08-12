<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Taux d'occupation moyen sur 30 jours (dashboard hôtelier).
 *
 * La statistique doit rester alignée sur le graphique d'occupation 7 jours :
 * une nuit J est occupée si arrivée <= J < départ, le jour du départ n'étant
 * pas une nuit occupée. Ces tests verrouillent cette règle et les deux cas où
 * elle se trompe le plus facilement : le séjour à cheval sur le début de
 * fenêtre, et l'établissement sans chambre déclarée.
 */
class DashboardAvgOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(User $user): array
    {
        return $this->actingAs($user)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk()
            ->json('data.month_insights');
    }

    public function test_avg_occupancy_is_null_when_no_room_is_configured(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 0]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        // 0 % laisserait croire à un établissement vide : la bonne réponse est
        // « pas encore mesurable ».
        $this->assertNull($this->dashboard($user)['avg_occupancy_30d']);
    }

    public function test_avg_occupancy_is_zero_without_any_stay(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 10]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        $this->assertSame(0, $this->dashboard($user)['avg_occupancy_30d']);
    }

    public function test_a_stay_covering_the_whole_window_gives_the_full_share_of_one_room(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 10]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        // Un séjour couvrant les 30 nuits de la fenêtre : 1 chambre sur 10 = 10 %.
        CheckIn::factory()->for($hotel)->create([
            'status'                  => 'active',
            'check_in_date'           => now()->subDays(40)->toDateString(),
            'expected_check_out_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertSame(10, $this->dashboard($user)['avg_occupancy_30d']);
    }

    public function test_the_departure_day_is_not_an_occupied_night(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 1]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        // Fenêtre = [j-29, j], soit 30 nuits. Ce séjour occupe les nuits j-29 à
        // j-25 incluses (5 nuits) : le départ tombe le j-24, qui ne compte pas.
        CheckIn::factory()->for($hotel)->create([
            'status'                  => 'completed',
            'check_in_date'           => now()->subDays(29)->toDateString(),
            'expected_check_out_date' => now()->subDays(24)->toDateString(),
            'actual_check_out_date'   => now()->subDays(24)->toDateString(),
        ]);

        // 5 nuits pleines sur 30 = 16,67 % → 17 après arrondi.
        $this->assertSame(17, $this->dashboard($user)['avg_occupancy_30d']);
    }

    public function test_a_stay_ending_before_the_window_is_ignored(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 5]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        CheckIn::factory()->for($hotel)->create([
            'status'                  => 'completed',
            'check_in_date'           => now()->subDays(60)->toDateString(),
            'expected_check_out_date' => now()->subDays(45)->toDateString(),
            'actual_check_out_date'   => now()->subDays(45)->toDateString(),
        ]);

        $this->assertSame(0, $this->dashboard($user)['avg_occupancy_30d']);
    }

    public function test_the_rate_is_capped_at_100_when_stays_exceed_the_room_count(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 1]);
        $user  = User::factory()->hotelAdmin($hotel)->create();

        // Deux séjours simultanés pour une seule chambre (surbooking ou chambre
        // non renseignée) : le taux quotidien reste plafonné à 100 %.
        foreach (range(1, 2) as $i) {
            CheckIn::factory()->for($hotel)->create([
                'status'                  => 'active',
                'check_in_date'           => now()->subDays(40)->toDateString(),
                'expected_check_out_date' => now()->addDays(10)->toDateString(),
            ]);
        }

        $this->assertSame(100, $this->dashboard($user)['avg_occupancy_30d']);
    }
}
