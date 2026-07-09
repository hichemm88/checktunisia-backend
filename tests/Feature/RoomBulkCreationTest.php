<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk room creation from a numeric range.
 *
 * Covers: happy-path range, prefix/suffix + zero-pad, duplicate skipping,
 * invalid range rejection, and the hard 500-room cap.
 */
class RoomBulkCreationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private User  $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    private function bulk(array $payload)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/organization/properties/{$this->hotel->id}/rooms/bulk", $payload);
    }

    public function test_creates_a_full_range_of_rooms(): void
    {
        $res = $this->bulk([
            'start' => 100, 'end' => 145, 'type' => 'double', 'capacity' => 2, 'floor' => 1,
        ])->assertCreated();

        $this->assertEquals(46, $res->json('data.created_count'));
        $this->assertEquals(0, $res->json('data.skipped_count'));
        $this->assertEquals(46, Room::where('hotel_id', $this->hotel->id)->count());
        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => '100']);
        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => '145']);
    }

    public function test_applies_prefix_suffix_and_zero_padding(): void
    {
        $res = $this->bulk([
            'start' => 1, 'end' => 3, 'prefix' => 'A-', 'suffix' => 'b', 'pad' => true,
            'type' => 'standard', 'capacity' => 2,
        ])->assertCreated();

        $this->assertEquals(3, $res->json('data.created_count'));
        // end=3 → width 1, so no visible padding difference here; verify format
        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => 'A-1b']);
        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => 'A-3b']);
    }

    public function test_zero_pads_to_end_width(): void
    {
        $this->bulk([
            'start' => 8, 'end' => 12, 'pad' => true, 'type' => 'standard', 'capacity' => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => '08']);
        $this->assertDatabaseHas('rooms', ['hotel_id' => $this->hotel->id, 'number' => '12']);
    }

    public function test_skips_existing_room_numbers(): void
    {
        Room::factory()->create(['hotel_id' => $this->hotel->id, 'number' => '150']);

        $res = $this->bulk([
            'start' => 148, 'end' => 152, 'type' => 'standard', 'capacity' => 2,
        ])->assertCreated();

        $this->assertEquals(4, $res->json('data.created_count'));
        $this->assertEquals(1, $res->json('data.skipped_count'));
        $this->assertContains('150', $res->json('data.skipped'));
        // 148,149,151,152 created + the pre-existing 150 = 5 total
        $this->assertEquals(5, Room::where('hotel_id', $this->hotel->id)->count());
    }

    public function test_rejects_inverted_range(): void
    {
        $this->bulk([
            'start' => 200, 'end' => 100, 'type' => 'standard', 'capacity' => 2,
        ])->assertStatus(422);
    }

    public function test_enforces_bulk_cap(): void
    {
        $this->bulk([
            'start' => 0, 'end' => 600, 'type' => 'standard', 'capacity' => 2,
        ])->assertStatus(422);

        $this->assertEquals(0, Room::where('hotel_id', $this->hotel->id)->count());
    }

    public function test_stores_building_in_metadata(): void
    {
        $this->bulk([
            'start' => 301, 'end' => 302, 'building' => 'Bloc B',
            'type' => 'standard', 'capacity' => 2,
        ])->assertCreated();

        $room = Room::where('hotel_id', $this->hotel->id)->where('number', '301')->first();
        $this->assertEquals('Bloc B', $room->metadata['building'] ?? null);
    }
}
