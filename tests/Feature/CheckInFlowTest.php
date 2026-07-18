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

    // Régression : corriger un champ d'identité d'un voyageur dont le document est
    // en code pays alpha-3 (« GBR ») ne doit pas être rejeté. L'ancienne règle de
    // l'update exigeait size:2 alors que la création accepte min:2/max:3 et que la
    // colonne est char(3) — corriger un simple prénom échouait.
    public function test_can_update_guest_identity_with_alpha3_document_country(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $guestId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", [
                'first_name'       => 'Ammar Abdula',
                'last_name'        => 'Shaif',
                'date_of_birth'    => '1993-07-21',
                'sex'              => 'M',
                'nationality_code' => 'YEM',
                'is_primary'       => true,
                'document'         => [
                    'type'                 => 'passport',
                    'document_number'      => '600580899',
                    'issuing_country_code' => 'GBR',
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        // Correction du prénom uniquement — aucun champ document envoyé.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$guestId}", [
                'first_name' => 'Ammar Abdulla',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ammar Abdulla');

        // Et modifier explicitement le pays de délivrance en alpha-3 doit passer.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$guestId}", [
                'document' => ['issuing_country_code' => 'FRA'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('travel_documents', ['issuing_country_code' => 'FRA']);
    }

    public function test_can_add_guest_to_active_checkin(): void
    {
        // A guest arriving hours after the primary — the stay is already active.
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", [
                'first_name'       => 'Latecomer',
                'last_name'        => 'Guest',
                'date_of_birth'    => '1990-06-20',
                'sex'              => 'F',
                'nationality_code' => 'FRA',
                'is_primary'       => false,
                'document'         => [
                    'type'                 => 'passport',
                    'document_number'      => 'FR55555555',
                    'issuing_country_code' => 'FR',
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('travel_documents', ['document_number' => 'FR55555555']);
    }

    public function test_cannot_add_guest_to_completed_checkin(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->completed()->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", [
                'first_name'       => 'Too',
                'last_name'        => 'Late',
                'date_of_birth'    => '1990-06-20',
                'sex'              => 'M',
                'nationality_code' => 'TUN',
                'document'         => [
                    'type'                 => 'passport',
                    'document_number'      => 'TN00000001',
                    'issuing_country_code' => 'TN',
                ],
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('travel_documents', ['document_number' => 'TN00000001']);
    }

    // ── Traveler uniqueness & stay history (Task 5) ───────────────────────────

    public function test_same_document_reuses_guest_and_keeps_stay_history(): void
    {
        $doc = ['type' => 'passport', 'document_number' => 'TN70001122', 'issuing_country_code' => 'TN'];
        $guestData = [
            'first_name' => 'Rania', 'last_name' => 'Cherif',
            'date_of_birth' => '1992-02-02', 'sex' => 'F', 'nationality_code' => 'TUN',
            'document' => $doc,
        ];

        $ci1 = CheckIn::factory()->for($this->hotel)->active()->create(['created_by' => $this->receptionist->id]);
        $ci2 = CheckIn::factory()->for($this->hotel)->active()->create(['created_by' => $this->receptionist->id]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$ci1->id}/guests", $guestData)->assertCreated();
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$ci2->id}/guests", $guestData)->assertCreated();

        // Exactly one TravelDocument for that passport…
        $this->assertEquals(1, \App\Models\TravelDocument::where('document_number', 'TN70001122')->count());
        // …owned by a single reused Guest (last_name is stored upper-cased)…
        $this->assertEquals(1, \App\Models\Guest::where('last_name', 'CHERIF')->count());
        // …with two stays recorded (history preserved).
        $guest = \App\Models\TravelDocument::where('document_number', 'TN70001122')->first()->guest;
        $this->assertEquals(2, $guest->checkIns()->count());
    }

    public function test_same_document_number_from_different_country_are_distinct_people(): void
    {
        $ci1 = CheckIn::factory()->for($this->hotel)->active()->create(['created_by' => $this->receptionist->id]);
        $ci2 = CheckIn::factory()->for($this->hotel)->active()->create(['created_by' => $this->receptionist->id]);

        $this->actingAs($this->receptionist)->postJson("/api/v1/hotel/check-ins/{$ci1->id}/guests", [
            'first_name' => 'Ali', 'last_name' => 'FromTunisia',
            'date_of_birth' => '1990-01-01', 'sex' => 'M', 'nationality_code' => 'TUN',
            'document' => ['type' => 'passport', 'document_number' => 'SHARED123', 'issuing_country_code' => 'TN'],
        ])->assertCreated();

        $this->actingAs($this->receptionist)->postJson("/api/v1/hotel/check-ins/{$ci2->id}/guests", [
            'first_name' => 'Marie', 'last_name' => 'FromFrance',
            'date_of_birth' => '1985-05-05', 'sex' => 'F', 'nationality_code' => 'FRA',
            'document' => ['type' => 'passport', 'document_number' => 'SHARED123', 'issuing_country_code' => 'FR'],
        ])->assertCreated();

        // Two DISTINCT guests — the shared number must not merge them (last_name upper-cased).
        $this->assertEquals(2, \App\Models\Guest::whereIn('last_name', ['FROMTUNISIA', 'FROMFRANCE'])->count());
        $this->assertEquals(2, \App\Models\TravelDocument::where('document_number', 'SHARED123')->count());
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
