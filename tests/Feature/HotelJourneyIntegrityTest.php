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
 * Le parcours réception, éprouvé aux endroits où il peut se contredire.
 *
 * ── Ce que ce fichier cherche ───────────────────────────────────────────
 *
 * Les transitions (finaliser, clôturer, annuler, revenir sur un départ) sont
 * toutes verrouillées et re-contrôlées sous verrou : la concurrence brute est
 * déjà traitée. Ce qui reste à trouver, ce sont les INVARIANTS qu'une
 * transition respecte et qu'une autre oublie — la même famille que les règles
 * de validation qui s'affaiblissaient entre `store` et `update`.
 *
 * L'invariant central du parcours : **une chambre ne porte qu'un seul séjour
 * ouvert**. Il est imposé à la création et au changement de chambre. La
 * question est de savoir si tous les autres chemins le respectent.
 */
class HotelJourneyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /** Compteur de numeros de document, unique dans le test. */
    private static int $docSeq = 0;

    private Hotel $hotel;

    private User $admin;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 5]);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
    }

    // ── L'invariant « une chambre, un séjour ouvert » ────────────────────────

    public function test_a_room_cannot_hold_two_open_stays_at_creation(): void
    {
        $room = Room::factory()->for($this->hotel)->create();

        $this->newStay($room)->assertCreated();
        $this->newStay($room)->assertStatus(422)->assertJsonPath('errors.0.code', 'ROOM_OCCUPIED');
    }

    public function test_reverting_a_checkout_cannot_resurrect_a_stay_into_an_occupied_room(): void
    {
        /*
         * Le trou. « Une chambre, un séjour ouvert » est imposé à la création
         * et au changement de chambre — pas au retour sur un départ.
         *
         * Le scénario est celui d'un comptoir ordinaire :
         *   1. le client de la 204 part, on enregistre le départ ;
         *   2. la chambre étant libre, on y installe le client suivant ;
         *   3. on s'aperçoit que le premier départ était une erreur de saisie
         *      et on l'annule.
         *
         * Le séjour ressuscité redevient « actif » DANS une chambre déjà
         * occupée. Deux séjours ouverts sur la même chambre : l'occupation
         * compte deux fois, la chambre affiche deux clients, et plus personne
         * ne peut y installer qui que ce soit.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '204']);

        $first = $this->activeStay($room);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$first->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])
            ->assertOk();

        // La chambre est libre : le client suivant s'installe légitimement.
        $second = $this->activeStay($room);

        // Le retour sur le départ doit être REFUSÉ tant que la chambre est
        // reprise — sinon il casse un invariant que tout le reste suppose.
        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$first->id}/revert-checkout")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ROOM_OCCUPIED');

        $openOnRoom = CheckIn::where('room_id', $room->id)
            ->whereIn('status', ['draft', 'active'])
            ->count();

        $this->assertSame(1, $openOnRoom, 'deux séjours ouverts sur la même chambre');
        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_reverting_a_checkout_still_works_when_the_room_is_free(): void
    {
        // Contre-épreuve : le durcissement ne doit pas casser le cas normal,
        // qui est la raison d'être de cette fonction — corriger un départ
        // enregistré par erreur.
        $room = Room::factory()->for($this->hotel)->create();
        $stay = $this->activeStay($room);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/revert-checkout")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNull($stay->fresh()->actual_check_out_date);
    }

    public function test_a_stay_without_a_room_can_always_be_reverted(): void
    {
        // Un séjour sans chambre attribuée n'entre en conflit avec personne :
        // le contrôle ne doit pas le bloquer au prétexte qu'il ne sait pas
        // quoi vérifier.
        $stay = $this->activeStay(null);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/revert-checkout")
            ->assertOk();
    }

    // ── Idempotence des transitions ──────────────────────────────────────────

    public function test_completing_twice_declares_the_stay_once(): void
    {
        $stay = $this->draftWithGuest();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/complete")->assertOk();

        // Double clic / rejeu après timeout : la seconde tentative doit être
        // refusée, pas exécutée. Deux finalisations = deux fiches de police.
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/complete")->assertStatus(422);

        // Une seule declaration : le journal d'audit le dit directement, sans
        // dependre de la plomberie de quota (qui a ses propres tests).
        $this->assertSame(
            1,
            \App\Models\AuditLog::where('action', 'check_in.completed')
                ->where('subject_id', $stay->id)
                ->count(),
            'la fiche a ete declaree deux fois',
        );
        $this->assertSame('active', $stay->fresh()->status);
    }

    public function test_a_stay_cannot_be_completed_without_a_guest(): void
    {
        $stay = CheckIn::factory()->for($this->hotel)->draft()->create(['created_by' => $this->admin->id]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/complete")
            ->assertStatus(422);

        $this->assertSame('draft', $stay->fresh()->status);
    }

    public function test_a_completed_stay_is_frozen_for_edits_and_guests(): void
    {
        $stay = $this->draftWithGuest();
        $guest = $stay->guests()->first();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])->assertOk();

        // Une fiche clôturée est un document déclaré : elle ne se retouche plus.
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/hotel/check-ins/{$stay->id}", ['notes' => 'après coup'])
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/hotel/check-ins/{$stay->id}/guests/{$guest->id}", ['first_name' => 'Autre'])
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/check-ins/{$stay->id}/guests/{$guest->id}")
            ->assertStatus(409);
    }

    public function test_checking_out_twice_keeps_the_first_departure(): void
    {
        $stay = $this->draftWithGuest();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->addDay()->toDateString(),
            ])->assertStatus(409);

        $this->assertSame(today()->toDateString(), $stay->fresh()->actual_check_out_date->toDateString());
    }

    public function test_a_departure_cannot_predate_the_arrival(): void
    {
        $stay = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->admin->id,
            'check_in_date' => today()->toDateString(),
            'expected_check_out_date' => today()->addDays(3)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->subDay()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_cancelling_twice_keeps_the_first_reason(): void
    {
        $stay = $this->draftWithGuest();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/cancel", ['reason' => 'motif initial'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/cancel", ['reason' => 'motif ecrase'])
            ->assertStatus(409);

        $this->assertSame('motif initial', $stay->fresh()->metadata['cancel_reason'] ?? null);
    }

    // ── Doublons de voyageur ─────────────────────────────────────────────────

    public function test_the_same_document_written_differently_reuses_one_traveller(): void
    {
        $first = $this->draftStay();
        $second = $this->draftStay();

        $this->addGuest($first, ['document' => [
            'type' => 'passport', 'document_number' => 'X0000000', 'issuing_country_code' => 'TUN',
        ]])->assertCreated();

        // Casse et séparateurs différents : c'est la MÊME personne, et son
        // historique de séjours ne doit pas être coupé en deux.
        $this->addGuest($second, ['document' => [
            'type' => 'passport', 'document_number' => 'x0 000-000', 'issuing_country_code' => 'tn',
        ]])->assertCreated();

        $this->assertSame(1, Guest::count(), 'le même passeport a créé deux voyageurs');
    }

    public function test_a_guest_cannot_be_added_twice_to_the_same_stay(): void
    {
        $stay = $this->draftStay();

        $this->addGuest($stay)->assertCreated();
        $this->addGuest($stay)->assertCreated(); // rapprochement, pas duplication

        $this->assertSame(1, $stay->fresh()->guests()->count());
    }


    // ── Concurrence ──────────────────────────────────────────────────────────

    /*
     * Ce qu'un double clic produit REELLEMENT cote serveur : deux requetes qui
     * ont chacune charge le sejour AVANT que l'autre n'ecrive. Les deux voient
     * « draft ». Rejouer la meme requete apres un timeout reseau donne
     * exactement la meme situation.
     *
     * C'est ce que `lockFresh()` protege : relire la ligne SOUS VERROU dans la
     * transaction, au lieu de faire confiance a l'instance deja chargee. Les
     * tests ci-dessous passent deux instances DISTINCTES et perimees au
     * service — la seule facon de reproduire la course de maniere deterministe
     * dans un test mono-processus.
     */

    public function test_two_simultaneous_completions_declare_the_stay_once(): void
    {
        $stay = $this->draftWithGuest();
        $service = app(\App\Services\CheckIn\CheckInService::class);

        // Deux instances chargees avant toute ecriture : les deux portent
        // « draft » en memoire.
        $requestA = CheckIn::find($stay->id);
        $requestB = CheckIn::find($stay->id);

        $service->complete($requestA, $this->receptionist);

        $this->expectException(\DomainException::class);
        $service->complete($requestB, $this->receptionist);
    }

    public function test_two_simultaneous_checkouts_record_one_departure(): void
    {
        $stay = $this->activeStay(null);
        $service = app(\App\Services\CheckIn\CheckInService::class);

        $requestA = CheckIn::find($stay->id);
        $requestB = CheckIn::find($stay->id);

        $service->checkout($requestA, today()->toDateString(), $this->admin);

        $this->expectException(\DomainException::class);
        $service->checkout($requestB, today()->addDay()->toDateString(), $this->admin);
    }

    public function test_two_simultaneous_cancellations_keep_the_first_reason(): void
    {
        $stay = $this->draftWithGuest();
        $service = app(\App\Services\CheckIn\CheckInService::class);

        $requestA = CheckIn::find($stay->id);
        $requestB = CheckIn::find($stay->id);

        $service->cancel($requestA, 'motif initial', $this->admin);

        try {
            $service->cancel($requestB, 'motif ecrase', $this->admin);
            $this->fail('la seconde annulation aurait du etre refusee');
        } catch (\DomainException) {
            // attendu
        }

        $this->assertSame('motif initial', $stay->fresh()->metadata['cancel_reason'] ?? null);
    }

    public function test_two_simultaneous_reverts_reopen_the_stay_once(): void
    {
        $stay = $this->activeStay(null);
        $service = app(\App\Services\CheckIn\CheckInService::class);

        $service->checkout(CheckIn::find($stay->id), today()->toDateString(), $this->admin);

        $requestA = CheckIn::find($stay->id);
        $requestB = CheckIn::find($stay->id);

        $service->revertCheckout($requestA, $this->admin);

        $this->expectException(\DomainException::class);
        $service->revertCheckout($requestB, $this->admin);
    }

    public function test_the_same_document_registered_concurrently_is_reported_not_crashed(): void
    {
        /*
         * Deux receptionnistes saisissent le meme passeport au meme instant.
         * Le rapprochement fait un SELECT puis un INSERT : entre les deux,
         * l'autre saisie a pu ecrire. C'est l'index d'unicite qui tranche.
         *
         * Ce qui compte est la REMONTEE : une erreur 500 muette laisserait la
         * reception sans savoir si le voyageur est enregistre.
         */
        $stay = $this->draftStay();

        // La piece existe deja, posee par « l'autre saisie ».
        $other = Guest::factory()->create();
        TravelDocument::create([
            'guest_id' => $other->id,
            'type' => 'passport',
            'document_number' => 'CONCURRENT1',
            'issuing_country_code' => 'TUN',
        ]);

        $response = $this->addGuest($stay, ['document' => [
            'type' => 'passport',
            'document_number' => 'CONCURRENT1',
            'issuing_country_code' => 'TUN',
        ]]);

        // Rapprochement reussi (meme personne) OU refus explicite — jamais un
        // 500, et jamais un doublon.
        $this->assertContains($response->status(), [201, 422], 'la course a produit une erreur serveur');
        $this->assertSame(1, TravelDocument::where('document_number', 'CONCURRENT1')->count());
    }


    // ── Chambres : cohérence avec les séjours en cours ───────────────────────

    public function test_a_room_holding_an_open_stay_cannot_be_deleted(): void
    {
        /*
         * `Room` est en suppression LOGIQUE et la cle etrangere est
         * `nullOnDelete` — qui ne se declenche donc jamais. La chambre part en
         * `deleted_at`, le sejour garde son `room_id`, et la relation rend
         * `null` : un client actif se retrouve sans chambre a l'ecran, dans le
         * tableau de bord et sur sa fiche, sans qu'aucune erreur ne soit levee.
         *
         * La chambre reste par ailleurs « occupee » du point de vue du controle
         * de conflit, qui interroge les sejours et non les chambres : elle
         * devient invisible ET bloquante.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '301']);
        $stay = $this->activeStay($room);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ROOM_OCCUPIED');

        $this->assertNotNull($stay->fresh()->room, 'le sejour actif a perdu sa chambre');
    }

    public function test_a_free_room_can_still_be_deleted(): void
    {
        // Contre-epreuve : le durcissement ne doit pas empecher de nettoyer le
        // plan de l'etablissement.
        $room = Room::factory()->for($this->hotel)->create(['number' => '302']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertNoContent();
    }

    public function test_a_room_whose_stays_are_all_closed_can_be_deleted(): void
    {
        // Un historique de sejours termines n'est pas une occupation : la
        // chambre doit rester supprimable.
        $room = Room::factory()->for($this->hotel)->create(['number' => '303']);
        $stay = $this->activeStay($room);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/hotel/check-ins/{$stay->id}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertNoContent();
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function newStay(?Room $room)
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/hotel/check-ins', [
            'room_id' => $room?->id,
            'check_in_date' => today()->toDateString(),
            'expected_check_out_date' => today()->addDays(2)->toDateString(),
        ]);
    }

    private function draftStay(): CheckIn
    {
        return CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->admin->id,
        ]);
    }

    private function activeStay(?Room $room): CheckIn
    {
        $stay = CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id,
            'status' => 'active',
            'room_id' => $room?->id,
            'check_in_date' => today()->subDay()->toDateString(),
            'expected_check_out_date' => today()->addDays(2)->toDateString(),
        ]);

        $guest = Guest::factory()->create();
        // Compteur, et non un morceau de l'identifiant : les UUIDv7 partagent
        // un prefixe temporel, donc `substr($id, 0, 8)` donnait le meme numero
        // de document a deux sejours crees dans la meme fenetre — collision sur
        // la contrainte d'unicite, sans rapport avec ce qu'on teste.
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'DOC-'.str_pad((string) ++self::$docSeq, 6, '0', STR_PAD_LEFT),
            'issuing_country_code' => 'TUN',
        ]);
        $stay->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $this->admin->id]);

        return $stay;
    }

    private function draftWithGuest(): CheckIn
    {
        $stay = $this->draftStay();
        $this->addGuest($stay)->assertCreated();

        return $stay->fresh();
    }

    private function addGuest(CheckIn $stay, array $over = [])
    {
        return $this->actingAs($this->receptionist)->postJson(
            "/api/v1/hotel/check-ins/{$stay->id}/guests",
            array_merge([
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
            ], $over),
        );
    }
}
