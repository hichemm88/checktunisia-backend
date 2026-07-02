<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation tests.
 * Verify that hotel A staff CANNOT access any data belonging to hotel B.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotelA;
    private Hotel $hotelB;
    private User  $adminA;
    private User  $adminB;
    private User  $receptA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotelA = Hotel::factory()->withActiveSubscription()->create(['name' => 'Hotel Alpha']);
        $this->hotelB = Hotel::factory()->withActiveSubscription()->create(['name' => 'Hotel Beta']);

        $this->adminA  = User::factory()->hotelAdmin($this->hotelA)->create();
        $this->adminB  = User::factory()->hotelAdmin($this->hotelB)->create();
        $this->receptA = User::factory()->receptionist($this->hotelA)->create();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_hotel_a_admin_can_read_own_dashboard(): void
    {
        $this->actingAs($this->adminA)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk();
    }

    // ── Check-ins ─────────────────────────────────────────────────────────────

    public function test_hotel_a_cannot_read_hotel_b_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotelB)->create();

        $this->actingAs($this->adminA)
            ->getJson("/api/v1/hotel/check-ins/{$checkIn->id}")
            ->assertNotFound();
    }

    public function test_hotel_a_cannot_list_hotel_b_checkins(): void
    {
        CheckIn::factory()->for($this->hotelB)->count(3)->create();

        $response = $this->actingAs($this->adminA)
            ->getJson('/api/v1/hotel/check-ins')
            ->assertOk();

        // Should return 0 results (hotel B's check-ins not visible)
        $this->assertCount(0, $response->json('data'));
    }

    public function test_hotel_a_cannot_complete_hotel_b_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotelB)->draft()->create();

        $this->actingAs($this->adminA)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertNotFound();
    }

    public function test_hotel_a_cannot_checkout_hotel_b_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotelB)->active()->create();

        $this->actingAs($this->adminA)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/checkout", [
                'check_out_date' => now()->toDateString(),
            ])
            ->assertNotFound();
    }

    // ── Rooms ─────────────────────────────────────────────────────────────────

    public function test_hotel_a_cannot_modify_hotel_b_room(): void
    {
        $room = Room::factory()->for($this->hotelB)->create();

        $this->actingAs($this->adminA)
            ->patchJson("/api/v1/hotel/rooms/{$room->id}", ['number' => '999'])
            ->assertNotFound();
    }

    public function test_hotel_a_cannot_delete_hotel_b_room(): void
    {
        $room = Room::factory()->for($this->hotelB)->create();

        $this->actingAs($this->adminA)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertNotFound();
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    public function test_hotel_a_admin_cannot_manage_hotel_b_users(): void
    {
        $userB = User::factory()->receptionist($this->hotelB)->create();

        $this->actingAs($this->adminA)
            ->patchJson("/api/v1/hotel/users/{$userB->id}", ['first_name' => 'Hacked'])
            ->assertNotFound();
    }

    // ── Security hits ─────────────────────────────────────────────────────────

    public function test_hotel_a_cannot_acknowledge_hotel_b_watchlist_hit(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotelB)->active()->create();
        $hit = \App\Models\WatchlistHit::factory()->for($this->hotelB)->for($checkIn)->create();

        $this->actingAs($this->adminA)
            ->postJson("/api/v1/hotel/watchlist-hits/{$hit->id}/acknowledge")
            ->assertNotFound();
    }

    // ── Profile ───────────────────────────────────────────────────────────────

    public function test_hotel_a_cannot_update_hotel_b_profile(): void
    {
        $this->actingAs($this->adminA)
            ->patchJson('/api/v1/hotel/profile', ['name' => 'Injected'])
            ->assertOk(); // Updates THEIR OWN hotel, not hotel B

        $this->assertDatabaseHas('hotels', ['id' => $this->hotelA->id, 'name' => 'Injected']);
        $this->assertDatabaseMissing('hotels', ['id' => $this->hotelB->id, 'name' => 'Injected']);
    }

    // ── Receptionist cannot do admin operations ────────────────────────────────

    public function test_receptionist_cannot_create_users(): void
    {
        $this->actingAs($this->receptA)
            ->postJson('/api/v1/hotel/users', [
                'email'      => 'new@hotel.com',
                'first_name' => 'Test',
                'last_name'  => 'User',
                'role'       => 'receptionist',
                'password'   => 'Password1!Secret',
            ])
            ->assertForbidden();
    }

    public function test_receptionist_cannot_manage_rooms(): void
    {
        $this->actingAs($this->receptA)
            ->postJson('/api/v1/hotel/rooms', ['number' => '101', 'type' => 'single'])
            ->assertForbidden();
    }

    // ── Unauthenticated ───────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_hotel_routes(): void
    {
        $this->getJson('/api/v1/hotel/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/hotel/check-ins')->assertUnauthorized();
    }
}
