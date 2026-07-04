<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end check-in flow tests + watchlist trigger.
 *
 * Covers:
 *   - Draft creation
 *   - Guest attachment
 *   - Complete (draft → active) + watchlist trigger
 *   - Checkout (active → completed)
 *   - Cancellation
 *   - Watchlist hit created when flagged guest checks in
 */
class CheckInFlowTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private User  $admin;
    private User  $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel       = Hotel::factory()->withActiveSubscription()->create();
        $this->admin       = User::factory()->hotelAdmin($this->hotel)->create();
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
    }

    // ── 1. Create a draft check-in ────────────────────────────────────────────

    public function test_receptionist_can_create_draft_checkin(): void
    {
        $response = $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date'           => now()->toDateString(),
                'expected_check_out_date' => now()->addDays(2)->toDateString(),
                'adults_count'            => 2,
                'children_count'          => 0,
                'booking_source'          => 'direct',
            ])
            ->assertCreated();

        $this->assertEquals('draft', $response->json('data.status'));
        $this->assertStringStartsWith('QYD-', $response->json('data.reference'));
    }

    // ── 2. Add a guest to the check-in ───────────────────────────────────────

    public function test_can_add_guest_to_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", [
                'first_name'        => 'Ahmed',
                'last_name'         => 'Ben Salah',
                'date_of_birth'     => '1985-03-15',
                'sex'               => 'M',
                'nationality_code'  => 'TUN',
                'is_primary'        => true,
                'document'          => [
                    'type'                 => 'passport',
                    'document_number'      => 'TN12345678',
                    'issuing_country_code' => 'TN',
                    'expiry_date'          => now()->addYears(5)->toDateString(),
                ],
            ])
            ->assertCreated();

        $this->assertEquals('Ahmed', $response->json('data.first_name'));
        $this->assertDatabaseHas('travel_documents', ['document_number' => 'TN12345678']);
    }

    // ── 3. Complete the check-in (draft → active) ─────────────────────────────

    public function test_completing_checkin_changes_status_to_active(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertEquals('active', $response->json('data.status'));
        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id, 'status' => 'active']);
    }

    public function test_cannot_complete_already_active_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertUnprocessable(); // 422 — validation / domain error
    }

    // ── 4. Checkout ───────────────────────────────────────────────────────────

    public function test_checkout_sets_status_completed(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $response = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/checkout", [
                'actual_check_out_date' => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertEquals('completed', $response->json('data.status'));
    }

    // ── 5. Cancellation ───────────────────────────────────────────────────────

    public function test_can_cancel_draft_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/cancel", [
                'reason' => 'Client did not show up.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id, 'status' => 'cancelled']);
    }

    // ── 6. Watchlist trigger on complete ──────────────────────────────────────

    public function test_completing_checkin_triggers_watchlist_hit_for_flagged_guest(): void
    {
        $org = AuthorityOrganization::factory()->ministry()->create();

        // Add the guest's document number to the watchlist
        WatchlistEntry::factory()->for($org)->create([
            'added_by'        => $this->admin->id,
            'document_number' => 'TN99999999',
            'severity'        => 'critique',
            'reason_code'     => 'MANDAT_ARRET',
            'status'          => 'active',
        ]);

        // Create draft check-in with the flagged guest
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        // Add the flagged guest via the API (so documents are properly linked)
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", [
                'first_name'       => 'Omar',
                'last_name'        => 'Flagged',
                'date_of_birth'    => '1980-01-01',
                'sex'              => 'M',
                'nationality_code' => 'TUN',
                'is_primary'       => true,
                'document'         => [
                    'type'                 => 'passport',
                    'document_number'      => 'TN99999999',  // matches watchlist
                    'issuing_country_code' => 'TN',
                ],
            ])
            ->assertCreated();

        // Complete the check-in — watchlist trigger should fire
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        // A WatchlistHit must have been created for this hotel
        $this->assertDatabaseHas('watchlist_hits', [
            'check_in_id' => $checkIn->id,
            'hotel_id'    => $this->hotel->id,
            'hit_type'    => 'document',
        ]);

        // The hotel dashboard should now show a pending security alert
        $dashResponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk();

        $this->assertGreaterThan(0, $dashResponse->json('data.pending_watchlist_hits'));
    }

    public function test_completing_checkin_with_clean_guest_creates_no_watchlist_hit(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Clean', 'Guest')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertDatabaseMissing('watchlist_hits', ['check_in_id' => $checkIn->id]);
    }

    // ── 7. Hotel security page shows pending hit ──────────────────────────────

    public function test_acknowledge_removes_hit_from_security_page(): void
    {
        $entry = WatchlistEntry::factory()->for(
            AuthorityOrganization::factory()->ministry()->create()
        )->create(['added_by' => $this->admin->id]);

        $checkIn = CheckIn::factory()->for($this->hotel)->active()->create([
            'created_by' => $this->admin->id,
        ]);
        $guest = \App\Models\Guest::factory()->create();

        $hit = WatchlistHit::create([
            'watchlist_entry_id' => $entry->id,
            'guest_id'           => $guest->id,
            'check_in_id'        => $checkIn->id,
            'hotel_id'           => $this->hotel->id,
            'hit_type'           => 'document',
            'notified_hotel_at'  => now(),
        ]);

        // Before acknowledge: should appear in list
        $before = $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/watchlist-hits')
            ->assertOk();
        $this->assertGreaterThan(0, $before->json('meta.total'));

        // Acknowledge
        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/watchlist-hits/{$hit->id}/acknowledge")
            ->assertOk();

        // After acknowledge: should no longer be in the pending list
        $after = $this->actingAs($this->admin)
            ->getJson('/api/v1/hotel/watchlist-hits')
            ->assertOk();
        $this->assertEquals(0, $after->json('meta.total'));
    }
}
