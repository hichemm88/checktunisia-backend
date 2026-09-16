<?php

namespace Tests\Feature\PartnerApi;

use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * Correspondance des chambres : un partenaire lit les VRAIES chambres Qayed
 * via GET /v1/establishments/{id}/rooms et envoie room_id — jamais un nom
 * texte à deviner côté Qayed (voir room_label, conservé pour affichage
 * uniquement).
 */
class RoomAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    public function test_lists_the_establishments_real_rooms(): void
    {
        $fixture = $this->setUpLinkedPartner();
        Room::factory()->for($fixture['hotel'])->create(['number' => '101']);
        Room::factory()->for($fixture['hotel'])->create(['number' => '102']);

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson("/v1/establishments/{$fixture['hotel']->id}/rooms")
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertEqualsCanonicalizing(['101', '102'], collect($response->json('data'))->pluck('number')->all());
    }

    public function test_rooms_of_an_unlinked_establishment_are_not_listed(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['hotel' => $otherHotel] = $this->makeOrgWithHotel();

        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson("/v1/establishments/{$otherHotel->id}/rooms")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');
    }

    public function test_fiche_session_with_a_valid_room_id_assigns_the_real_room(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $room = Room::factory()->for($fixture['hotel'])->create(['number' => 'Chambre verte']);

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-ROOM-1', [], ['room_id' => $room->id]))
            ->assertCreated();

        $session = FicheSession::findOrFail($response->json('session_id'));
        $checkIn = CheckIn::findOrFail($session->check_in_id);
        $this->assertSame($room->id, $checkIn->room_id);
    }

    public function test_fiche_session_with_a_room_id_from_another_hotel_is_rejected(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['hotel' => $otherHotel] = $this->makeOrgWithHotel();
        $foreignRoom = Room::factory()->for($otherHotel)->create();

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-ROOM-2', [], ['room_id' => $foreignRoom->id]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(0, CheckIn::where('booking_reference', 'BK-ROOM-2')->count());
    }

    public function test_fiche_session_with_an_already_occupied_room_is_rejected_and_creates_nothing(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $room = Room::factory()->for($fixture['hotel'])->create();
        CheckIn::factory()->for($fixture['hotel'])->create(['room_id' => $room->id, 'status' => 'active']);

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-ROOM-3', [], ['room_id' => $room->id]));

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->assertSame(0, CheckIn::where('booking_reference', 'BK-ROOM-3')->count());
    }

    public function test_a_room_id_added_on_a_later_call_updates_the_still_draft_check_in(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $room = Room::factory()->for($fixture['hotel'])->create();

        // First call: no room yet (Diarna hasn't resolved it client-side).
        $first = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-ROOM-4'))
            ->assertCreated();
        $checkInId = FicheSession::findOrFail($first->json('session_id'))->check_in_id;
        $this->assertNull(CheckIn::find($checkInId)->room_id);

        // Second call for the same booking: room now known, still draft (never opened).
        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-ROOM-4', [], ['room_id' => $room->id]))
            ->assertOk();

        $this->assertSame($room->id, CheckIn::find($checkInId)->fresh()->room_id);
        $this->assertSame(1, CheckIn::where('booking_reference', 'BK-ROOM-4')->count());
    }

    public function test_by_booking_ref_lookup_returns_the_latest_session_status(): void
    {
        $fixture = $this->setUpLinkedPartner();

        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-LOOKUP-1'))
            ->assertCreated();

        $lookup = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiche-sessions/by-booking-ref?establishment_id='.$fixture['hotel']->id.'&booking_ref=BK-LOOKUP-1')
            ->assertOk();

        $this->assertSame($create->json('session_id'), $lookup->json('session_id'));
        $this->assertSame('pending', $lookup->json('status'));
    }

    public function test_by_booking_ref_lookup_404s_when_nothing_was_ever_created(): void
    {
        $fixture = $this->setUpLinkedPartner();

        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiche-sessions/by-booking-ref?establishment_id='.$fixture['hotel']->id.'&booking_ref=BK-NEVER')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'session_not_found');
    }
}
