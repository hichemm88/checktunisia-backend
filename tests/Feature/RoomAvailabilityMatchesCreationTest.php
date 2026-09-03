<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une chambre affichée « libre » doit pouvoir être réservée.
 *
 * ── Le défaut ───────────────────────────────────────────────────────────
 *
 * Deux règles d'occupation coexistaient sans dire la même chose :
 *
 *   sélecteur (RoomController::availability)         → chevauchement de DATES
 *   création  (CheckInController::roomConflictError) → tout séjour draft/active,
 *                                                      SANS condition de date
 *
 * Le sélecteur était donc strictement plus permissif. Il présentait « Libre »
 * des chambres que la création refusait systématiquement, et la réception ne
 * l'apprenait qu'après avoir choisi la chambre et validé — avec un
 * ROOM_OCCUPIED qu'aucun indice ne laissait prévoir, et sans moyen de deviner
 * quelles chambres auraient marché.
 *
 * Deux situations très ordinaires y menaient : un départ en retard, et une
 * chambre portant un brouillon pour des dates futures.
 *
 * ── Pourquoi ce test compare les deux endpoints ─────────────────────────
 *
 * Vérifier une règle isolément laisserait l'autre dériver — c'est exactement
 * ainsi que l'écart s'est creusé. Ce test prend donc CHAQUE chambre annoncée
 * libre et tente réellement de la réserver. Il ne connaît aucune des deux
 * règles : il ne vérifie que leur accord, ce qui reste vrai quelle que soit la
 * façon dont elles évoluent.
 *
 * Données strictement synthétiques.
 */
class RoomAvailabilityMatchesCreationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 6]);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    /** @return array<int,array<string,mixed>> */
    private function availability(string $from, string $to): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/v1/hotel/rooms/availability?from={$from}&to={$to}")
            ->assertOk()
            ->json('data');
    }

    private function stateOf(array $rooms, string $number): ?string
    {
        return collect($rooms)->firstWhere('number', $number)['state'] ?? null;
    }

    public function test_a_room_whose_guest_is_overdue_is_not_offered_as_free(): void
    {
        /*
         * Le cas de comptoir : le départ prévu est passé et personne n'a saisi
         * le check-out — le client a prolongé, ou la réception a oublié. Le
         * séjour reste « active », donc la création refusera la chambre.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '201']);
        CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id,
            'status' => 'active',
            'room_id' => $room->id,
            'check_in_date' => now()->subDays(20),
            'expected_check_out_date' => now()->subDays(10),
            'actual_check_out_date' => null,
        ]);

        $rooms = $this->availability(now()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame(
            'occupied',
            $this->stateOf($rooms, '201'),
            'un séjour en retard doit continuer à occuper sa chambre',
        );
    }

    public function test_a_room_held_by_a_future_draft_is_not_offered_as_free(): void
    {
        // Un brouillon posé sur des dates futures bloque déjà la chambre côté
        // création : l'afficher libre aujourd'hui promet l'impossible.
        $room = Room::factory()->for($this->hotel)->create(['number' => '202']);
        CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->admin->id,
            'room_id' => $room->id,
            'check_in_date' => now()->addDays(30),
            'expected_check_out_date' => now()->addDays(32),
        ]);

        $rooms = $this->availability(now()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame(
            'occupied',
            $this->stateOf($rooms, '202'),
            'un brouillon futur bloque déjà la création : il doit se voir',
        );
    }

    public function test_every_room_announced_free_can_actually_be_booked(): void
    {
        // Un parc mêlant chambres saines, séjour en retard et brouillon futur —
        // ce qu'on trouve dans un établissement réel.
        Room::factory()->for($this->hotel)->create(['number' => '301']);
        $retard = Room::factory()->for($this->hotel)->create(['number' => '302']);
        $futur = Room::factory()->for($this->hotel)->create(['number' => '303']);
        Room::factory()->for($this->hotel)->create(['number' => '304']);

        CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id, 'status' => 'active', 'room_id' => $retard->id,
            'check_in_date' => now()->subDays(15),
            'expected_check_out_date' => now()->subDays(5),
            'actual_check_out_date' => null,
        ]);
        CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->admin->id, 'room_id' => $futur->id,
            'check_in_date' => now()->addDays(20),
            'expected_check_out_date' => now()->addDays(22),
        ]);

        $from = now()->toDateString();
        $to = now()->addDay()->toDateString();

        $rooms = $this->availability($from, $to);
        $free = array_values(array_filter($rooms, fn ($r) => $r['state'] === 'free'));

        // Sans cette garde, « aucune chambre libre » satisferait la boucle
        // ci-dessous sans rien vérifier.
        $this->assertNotEmpty($free, 'aucune chambre libre : le test ne vérifierait rien');

        foreach ($free as $room) {
            $response = $this->actingAs($this->admin)->postJson('/api/v1/hotel/check-ins', [
                'check_in_date' => $from,
                'expected_check_out_date' => $to,
                'room_id' => $room['id'],
            ]);

            $this->assertSame(
                201,
                $response->status(),
                sprintf(
                    "La chambre %s est annoncée « libre » mais la création la refuse (%d %s).\n"
                    ."Le sélecteur promet ce que le serveur n'accepte pas.",
                    $room['number'],
                    $response->status(),
                    (string) ($response->json('errors.0.code') ?? ''),
                ),
            );

            // On libère aussitôt : chaque chambre doit être jugée sur l'état
            // d'origine, pas sur celui que le test vient de créer.
            CheckIn::where('id', $response->json('data.id'))->forceDelete();
        }

        // Contre-épreuve : les chambres saines restent bien proposées.
        $this->assertSame('free', $this->stateOf($rooms, '301'));
        $this->assertSame('free', $this->stateOf($rooms, '304'));
    }
}
