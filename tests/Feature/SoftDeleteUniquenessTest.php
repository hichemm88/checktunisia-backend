<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suppression logique et unicité : ce qui a disparu de l'écran occupe-t-il
 * encore sa place en base ?
 *
 * ── Le motif ────────────────────────────────────────────────────────────
 *
 * `Room`, `Guest`, `User`, `Hotel` et `CheckIn` sont en `SoftDeletes` : la
 * ligne reste, seul `deleted_at` est posé. Or les index d'unicité de ces
 * tables — sauf un — ne l'excluent pas :
 *
 *     rooms_hotel_id_number_unique      (hotel_id, number)          — global
 *     users_email_unique                (email)                     — global
 *     hotels_slug_unique                (slug)                      — global
 *     users_one_owner_per_org  ... WHERE role_org='owner' AND deleted_at IS NULL
 *
 * Le dernier est PARTIEL et fait exactement ce qu'il faut. Quelqu'un
 * connaissait donc le piège : les autres sont un oubli, pas une décision.
 *
 * Conséquence : ce qu'on croit avoir supprimé continue de bloquer. La
 * réception supprime la chambre 204, veut la recréer, et s'entend répondre
 * « ce numéro existe déjà » à propos d'une chambre qu'elle ne voit nulle part.
 *
 * ── Ce que ces tests établissent ────────────────────────────────────────
 *
 * Ils décrivent le comportement RÉEL, sans le juger d'avance. Certains cas
 * méritent un correctif, d'autres sont défendables — un numéro de fiche ne se
 * réutilise pas, et une adresse e-mail réattribuée poserait ses propres
 * problèmes. Le but est que le comportement soit connu et intentionnel plutôt
 * que subi.
 */
class SoftDeleteUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 5]);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    public function test_a_deleted_room_number_can_be_used_again(): void
    {
        /*
         * Le cas de comptoir : on supprime la 204 (erreur de saisie, chambre
         * renumérotée, étage refait), puis on veut la recréer. Sans exclusion
         * de `deleted_at`, l'index refuse — et l'écran annonce un conflit avec
         * une chambre invisible, sans aucun moyen de s'en sortir.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '204']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertNoContent();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/rooms', ['number' => '204', 'type' => 'double', 'capacity' => 2])
            ->assertCreated();
    }

    public function test_two_live_rooms_still_cannot_share_a_number(): void
    {
        // Contre-épreuve : l'unicité qui compte — celle des chambres VIVANTES —
        // doit rester entière.
        Room::factory()->for($this->hotel)->create(['number' => '301']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/rooms', ['number' => '301', 'type' => 'double', 'capacity' => 2])
            ->assertStatus(422);
    }

    public function test_the_same_number_may_exist_in_two_establishments(): void
    {
        // L'unicité est par établissement, pas globale : deux hôtels ont
        // chacun leur 204.
        $other = Hotel::factory()->withActiveSubscription()->create();
        Room::factory()->for($other)->create(['number' => '204']);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/hotel/rooms', ['number' => '204', 'type' => 'double', 'capacity' => 2])
            ->assertCreated();
    }

    public function test_a_traveller_whose_record_was_deleted_can_check_in_again(): void
    {
        /*
         * `travel_documents` n'est PAS en suppression logique, mais sa clef
         * étrangère cascade depuis `guests` — qui l'est. La cascade ne se
         * déclenche donc jamais : le document d'un voyageur supprimé continue
         * d'occuper son triplet (type, numéro, pays).
         *
         * Le même voyageur qui se represente au comptoir ne peut alors plus
         * être enregistré : le rapprochement ne voit pas la fiche supprimée,
         * tente d'en créer une, et bute sur l'unicité. Sur un parcours qui
         * porte une obligation de déclaration, c'est un blocage dur.
         */
        $guest = Guest::factory()->create(['last_name' => 'REVENANT']);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'X0000000',
            'issuing_country_code' => 'TUN',
        ]);

        $guest->delete();

        $stay = CheckIn::factory()->for($this->hotel)->draft()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/guests", [
                'first_name' => 'Foulen',
                'last_name' => 'El Foulani',
                'date_of_birth' => '1990-01-01',
                'sex' => 'M',
                'nationality_code' => 'TUN',
                'is_primary' => true,
                'document' => [
                    'type' => 'passport',
                    'document_number' => 'X0000000',
                    'issuing_country_code' => 'TUN',
                ],
            ])
            ->assertCreated();

        $this->assertSame(1, $stay->fresh()->guests()->count());
    }
}
