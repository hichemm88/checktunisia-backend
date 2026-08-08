<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Le parcours réception tel qu'il se vit vraiment : plusieurs agents, des
 * arrivées simultanées, un réseau qui coupe, des documents scannés deux fois.
 *
 * Chaque test correspond à une situation de comptoir, pas à une fonction :
 * l'invariant vérifié est toujours « une opération métier ne doit être
 * exécutée qu'une seule fois, et rien ne doit disparaître en silence ».
 */
class CheckInHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private User $receptionist;

    /** Second agent au même comptoir — les deux ont les mêmes droits. */
    private User $receptionist2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Qayed']);
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
        $this->receptionist2 = User::factory()->receptionist($this->hotel)->create();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21612345678@c.us',
            'whatsapp.worker_secret' => 'test-secret',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function guestPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Yasmine',
            'last_name' => 'Gharbi',
            'date_of_birth' => '1991-04-12',
            'sex' => 'F',
            'nationality_code' => 'TUN',
            'is_primary' => true,
            'document' => [
                'type' => 'passport',
                'document_number' => 'TN4455667',
                'issuing_country_code' => 'TUN',
            ],
        ], $overrides);
    }

    private function draft(?string $roomId = null): CheckIn
    {
        return CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
            'room_id' => $roomId,
        ]);
    }

    // ─── Doublons voyageur (§4) ───────────────────────────────────────────────

    /**
     * Le même passeport saisi une fois par le scan (« TUN », sans séparateur) et
     * une fois à la main (« tn », avec un espace) désigne UNE personne. Tant que
     * le triplet n'était pas normalisé, ce client fidèle repartait avec un
     * second dossier et son historique de séjours coupé en deux.
     */
    public function test_the_same_passport_written_differently_is_still_one_traveller(): void
    {
        $first = $this->draft();
        $second = $this->draft();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$first->id}/guests", $this->guestPayload())
            ->assertCreated();

        $this->actingAs($this->receptionist2)
            ->postJson("/api/v1/hotel/check-ins/{$second->id}/guests", $this->guestPayload([
                'document' => [
                    'type' => 'passport',
                    'document_number' => 'tn 44-556 67',
                    'issuing_country_code' => 'tn',
                ],
            ]))
            ->assertCreated();

        $this->assertSame(1, TravelDocument::where('document_number', 'TN4455667')->count());
        $this->assertSame(1, Guest::where('last_name', 'GHARBI')->count());

        $guest = TravelDocument::where('document_number', 'TN4455667')->first()->guest;
        $this->assertSame(2, $guest->checkIns()->count(), 'Les deux séjours doivent tenir sur le même dossier.');
    }

    /**
     * Même numéro de passeport, identité différente : le dossier existant fait
     * foi (un document identifie une personne), mais la réception doit VOIR
     * qu'elle réutilise un autre nom que celui qu'elle vient de taper — sinon
     * la fiche de police part au mauvais nom sans que personne le sache.
     */
    public function test_a_different_identity_on_a_known_document_is_reported_not_swallowed(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload())
            ->assertCreated();

        $response = $this->actingAs($this->receptionist2)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload([
                'first_name' => 'Karim',
                'last_name' => 'Mansour',
            ]))
            ->assertCreated();

        $this->assertTrue($response->json('data.matched_existing'));
        $this->assertEqualsCanonicalizing(
            ['first_name', 'last_name'],
            $response->json('data.identity_mismatch'),
        );
        $this->assertSame('Yasmine', $response->json('data.first_name'), 'Le dossier existant reste la source de vérité.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'guest.identity_mismatch']);
    }

    /**
     * Deux personnes différentes qui partagent un début de ressemblance ne
     * doivent jamais être fusionnées ni bloquées — seulement signalées quand
     * nom, prénom ET date de naissance coïncident.
     */
    public function test_a_look_alike_traveller_is_flagged_without_blocking_the_check_in(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload())
            ->assertCreated();

        // Même personne, autre type de document (carte d'identité) : nouveau
        // dossier légitime, mais la ressemblance est remontée.
        $flagged = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload([
                'first_name' => 'yasmine',
                'last_name' => 'GHÂRBI',
                'document' => ['type' => 'national_id', 'document_number' => '09887766', 'issuing_country_code' => 'TUN'],
            ]))
            ->assertCreated();

        $this->assertCount(1, $flagged->json('data.possible_duplicates'));

        // Un homonyme né un autre jour n'est PAS un doublon.
        $clean = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload([
                'date_of_birth' => '1975-09-30',
                'document' => ['type' => 'passport', 'document_number' => 'ZZ1112223', 'issuing_country_code' => 'FRA'],
            ]))
            ->assertCreated();

        $this->assertCount(0, $clean->json('data.possible_duplicates'));
    }

    // ─── Double action / concurrence (§7) ─────────────────────────────────────

    /**
     * Double clic sur « Finaliser » — ou retry du navigateur après un timeout
     * réseau alors que la première requête était déjà passée.
     * La seconde tentative doit être refusée, et surtout n'enfiler AUCUNE
     * seconde fiche de police.
     */
    public function test_finalising_twice_sends_the_police_record_only_once(): void
    {
        $checkIn = $this->draft();
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())
            ->assertCreated();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->actingAs($this->receptionist2)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertStatus(422);

        $this->assertSame(1, WhatsappSendLog::where('check_in_id', $checkIn->id)->count());
        $this->assertSame(1, CheckIn::where('id', $checkIn->id)->where('status', 'active')->count());
    }

    /**
     * Le worker redémarre / le job est rejoué : ré-enfiler un séjour déjà
     * finalisé ne doit rien produire de neuf. C'est le garde-fou qui manquait
     * à enqueueForCheckIn() alors qu'enqueueForGuest() l'avait déjà.
     */
    public function test_requeuing_a_finalised_stay_creates_no_second_record(): void
    {
        $checkIn = $this->draft();
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())
            ->assertCreated();
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $before = WhatsappSendLog::where('check_in_id', $checkIn->id)->count();

        app(WhatsappOutboxService::class)->enqueueForCheckIn($checkIn->fresh());
        app(WhatsappOutboxService::class)->enqueueForCheckIn($checkIn->fresh());

        $this->assertSame($before, WhatsappSendLog::where('check_in_id', $checkIn->id)->count());
    }

    /** Enregistrer le départ deux fois ne doit pas renotifier ni réécrire la date. */
    public function test_checking_out_twice_is_refused(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->create(['created_by' => $this->receptionist->id]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/checkout", ['actual_check_out_date' => now()->toDateString()])
            ->assertOk();

        $this->actingAs($this->receptionist2)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/checkout", ['actual_check_out_date' => now()->addDay()->toDateString()])
            ->assertStatus(409);

        $this->assertSame(now()->toDateString(), CheckIn::find($checkIn->id)->actual_check_out_date->toDateString());
    }

    /** Annuler un séjour déjà clôturé effacerait un départ réel. */
    public function test_cancelling_a_closed_stay_is_refused(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->completed()->create([
            'created_by' => $this->receptionist->id,
            'actual_check_out_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/cancel", ['reason' => 'Erreur de saisie'])
            ->assertStatus(409);

        $this->assertDatabaseHas('check_ins', ['id' => $checkIn->id, 'status' => 'completed']);
    }

    /**
     * Deux agents attribuent la même chambre au même moment. La seconde
     * réservation doit être refusée — une chambre ne porte qu'un séjour ouvert.
     */
    public function test_a_room_cannot_hold_two_open_stays(): void
    {
        $room = Room::factory()->for($this->hotel)->create(['number' => '204']);

        $payload = [
            'check_in_date' => now()->toDateString(),
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'room_id' => $room->id,
        ];

        $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/check-ins', $payload)
            ->assertCreated();

        $this->actingAs($this->receptionist2)
            ->postJson('/api/v1/hotel/check-ins', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'ROOM_OCCUPIED');

        $this->assertSame(1, CheckIn::where('room_id', $room->id)->count());
    }

    /**
     * Le même cliché téléversé deux fois (double appui sur le déclencheur, ou
     * retry après timeout) ne doit produire qu'un scan : sinon la fiche peut
     * partir avec la copie orpheline et le quota de scans est décompté deux fois.
     */
    public function test_uploading_the_same_photo_twice_yields_a_single_scan(): void
    {
        Storage::fake('local');
        $checkIn = $this->draft();

        $bytes = UploadedFile::fake()->image('passeport.jpg', 800, 600)->get();

        $ids = collect(range(1, 2))->map(function () use ($checkIn, $bytes) {
            $file = UploadedFile::fake()->createWithContent('passeport.jpg', $bytes);

            return $this->actingAs($this->receptionist)
                ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/scans", ['passport_image' => $file])
                ->assertStatus(202)
                ->json('data.scan_id');
        });

        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, DocumentScan::where('check_in_id', $checkIn->id)->count());
    }

    /**
     * En production, faute de pilote OCR serveur, la lecture ne doit PAS être
     * simulée : le pilote factice écrivait une identité inventée
     * (« MOCK / Test / TN<aléatoire> ») marquée « completed » à 95 % de
     * confiance, à côté de la photo d'un vrai passeport. Une lecture qui n'a
     * pas eu lieu doit se déclarer comme telle.
     */
    public function test_in_production_a_missing_ocr_driver_reports_no_reading_instead_of_inventing_one(): void
    {
        Storage::fake('local');
        $checkIn = $this->draft();

        app()->detectEnvironment(fn () => 'production');

        $scanId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/scans", [
                'passport_image' => UploadedFile::fake()->image('passeport.jpg'),
            ])
            ->assertStatus(202)
            ->json('data.scan_id');

        $scan = DocumentScan::find($scanId);

        $this->assertSame('skipped', $scan->ocr_status);
        $this->assertNull($scan->ocr_raw_result, 'Aucune donnée d\'identité ne doit être fabriquée.');
        $this->assertNull($scan->ocr_confidence);
        $this->assertNotNull($scan->file_path, 'L\'image, elle, reste conservée.');
    }

    // ─── Séjour de groupe (§6) ────────────────────────────────────────────────

    /**
     * Quatre voyageurs, deux chambres, une réservation : le cas de la famille
     * ou du petit groupe. Chacun doit avoir sa fiche, une seule fois, avec un
     * unique voyageur principal par séjour.
     */
    public function test_four_travellers_across_two_rooms_each_get_exactly_one_record(): void
    {
        $rooms = Room::factory()->count(2)->for($this->hotel)->create();

        $people = [
            ['Sami', 'Ayari', 'A1000001'], ['Leila', 'Ayari', 'A1000002'],
            ['Nour', 'Ayari', 'A1000003'], ['Ines', 'Ayari', 'A1000004'],
        ];

        $stays = [];
        foreach ($rooms as $i => $room) {
            $stay = $this->actingAs($this->receptionist)
                ->postJson('/api/v1/hotel/check-ins', [
                    'check_in_date' => now()->toDateString(),
                    'expected_check_out_date' => now()->addDays(3)->toDateString(),
                    'room_id' => $room->id,
                    'booking_reference' => 'GRP-2026-77',
                    'adults_count' => 2,
                ])
                ->assertCreated()
                ->json('data.id');

            foreach (array_slice($people, $i * 2, 2) as $n => $person) {
                $this->actingAs($this->receptionist)
                    ->postJson("/api/v1/hotel/check-ins/{$stay}/guests", $this->guestPayload([
                        'first_name' => $person[0],
                        'last_name' => $person[1],
                        'date_of_birth' => '1988-0'.($n + 1).'-1'.($i + 1),
                        'is_primary' => $n === 0,
                        'document' => ['type' => 'passport', 'document_number' => $person[2], 'issuing_country_code' => 'TUN'],
                    ]))
                    ->assertCreated();
            }

            $this->actingAs($this->receptionist)
                ->postJson("/api/v1/hotel/check-ins/{$stay}/complete")
                ->assertOk();

            $stays[] = $stay;
        }

        // Quatre fiches au total, une par voyageur, aucune en double.
        $this->assertSame(4, WhatsappSendLog::whereIn('check_in_id', $stays)->count());
        $this->assertSame(4, WhatsappSendLog::whereIn('check_in_id', $stays)->distinct('guest_id')->count('guest_id'));

        // Un seul voyageur principal par séjour.
        foreach ($stays as $stay) {
            $this->assertSame(1, \App\Models\CheckInGuest::where('check_in_id', $stay)->where('is_primary', true)->count());
        }

        // La réservation commune reste retrouvable par sa référence.
        $this->assertSame(2, CheckIn::where('booking_reference', 'GRP-2026-77')->count());
    }

    // ─── Correction après coup (§6.8) ─────────────────────────────────────────

    /**
     * Une faute de frappe sur le numéro de document repérée après le check-in
     * doit pouvoir être corrigée, et la fiche régénérée reprendre la valeur
     * corrigée — sans jamais créer une seconde fiche.
     */
    public function test_correcting_a_document_after_check_in_updates_the_record_without_duplicating_it(): void
    {
        $checkIn = $this->draft();
        $guestId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())
            ->assertCreated()->json('data.id');

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();

        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$guestId}", [
                'document' => ['document_number' => 'tn 999 0001'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('travel_documents', ['document_number' => 'TN9990001']);

        $job = WhatsappSendLog::where('check_in_id', $checkIn->id)->sole();
        app(WhatsappOutboxService::class)->resend($job);

        $this->assertSame(1, WhatsappSendLog::where('check_in_id', $checkIn->id)->count());
        $this->assertStringContainsString('TN9990001', $job->fresh()->caption);
    }

    /** Corriger un numéro vers celui d'un AUTRE voyageur doit être refusé proprement, pas en erreur 500. */
    public function test_reassigning_a_document_number_already_taken_is_refused(): void
    {
        $checkIn = $this->draft();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())->assertCreated();

        $otherId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload([
                'first_name' => 'Hedi', 'last_name' => 'Brahmi', 'date_of_birth' => '1979-11-02',
                'is_primary' => false,
                'document' => ['type' => 'passport', 'document_number' => 'TN0000009', 'issuing_country_code' => 'TUN'],
            ]))
            ->assertCreated()->json('data.id');

        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$otherId}", [
                'document' => ['document_number' => 'TN4455667'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'DOCUMENT_ALREADY_USED');
    }

    /**
     * Un second scan dont le MRZ ne rend pas l'expiration ne doit pas effacer
     * la date déjà connue : la fiche de police repartirait sans elle.
     */
    public function test_a_later_partial_scan_never_erases_a_known_expiry_date(): void
    {
        $expiry = now()->addYears(4)->toDateString();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload([
                'document' => [
                    'type' => 'passport', 'document_number' => 'TN4455667',
                    'issuing_country_code' => 'TUN', 'expiry_date' => $expiry,
                ],
            ]))->assertCreated();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$this->draft()->id}/guests", $this->guestPayload())
            ->assertCreated();

        $this->assertSame($expiry, TravelDocument::where('document_number', 'TN4455667')->first()->expiry_date->toDateString());
    }

    /** Un document périmé n'empêche pas le séjour mais doit rester visible dans la liste. */
    public function test_an_expired_document_is_accepted_and_flagged(): void
    {
        $checkIn = $this->draft();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload([
                'document' => [
                    'type' => 'passport', 'document_number' => 'TN4455667',
                    'issuing_country_code' => 'TUN', 'expiry_date' => now()->subMonths(3)->toDateString(),
                ],
            ]))
            ->assertCreated();

        $row = collect($this->actingAs($this->receptionist)->getJson('/api/v1/hotel/check-ins')->json('data'))
            ->firstWhere('id', $checkIn->id);

        $this->assertTrue($row['document_expired']);
    }

    // ─── Isolation entre établissements (§12) ─────────────────────────────────

    /**
     * L'établissement A ne doit toucher NI aux voyageurs, NI aux scans d'un
     * séjour de l'établissement B — c'est le point où une fuite serait la plus
     * dommageable, puisqu'il s'agit de pièces d'identité.
     */
    public function test_a_property_cannot_reach_another_propertys_guests_or_scans(): void
    {
        Storage::fake('local');

        $other = Hotel::factory()->withActiveSubscription()->create();
        $otherStay = CheckIn::factory()->for($other)->draft()->create([
            'created_by' => User::factory()->hotelAdmin($other)->create()->id,
        ]);
        $otherScan = DocumentScan::create([
            'check_in_id' => $otherStay->id,
            'file_path' => 'scans/x.jpg',
            'file_hash' => str_repeat('a', 64),
            'mime_type' => 'image/jpeg',
            'ocr_status' => 'completed',
            'uploaded_by' => $this->admin->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$otherStay->id}/guests", $this->guestPayload())
            ->assertNotFound();

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$otherStay->id}/scans", [
                'passport_image' => UploadedFile::fake()->image('p.jpg'),
            ])
            ->assertNotFound();

        $this->actingAs($this->receptionist)
            ->getJson("/api/v1/hotel/scans/{$otherScan->id}/status")
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/check-ins/{$otherStay->id}")
            ->assertNotFound();

        $this->assertDatabaseMissing('check_in_guests', ['check_in_id' => $otherStay->id]);
    }

    // ─── Traçabilité (§11) ────────────────────────────────────────────────────

    /** Qui a fait quoi, sur quel voyageur, à quel moment — de l'arrivée au départ. */
    public function test_the_whole_stay_leaves_an_audit_trail(): void
    {
        // Créé via l'API et non par factory : c'est le parcours réel qui doit
        // laisser la trace, depuis l'ouverture de la fiche.
        $checkIn = (object) ['id' => $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date' => now()->toDateString(),
                'expected_check_out_date' => now()->addDays(2)->toDateString(),
            ])
            ->assertCreated()->json('data.id')];

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())->assertCreated();
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")->assertOk();
        $this->actingAs($this->receptionist2)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/checkout", ['actual_check_out_date' => now()->toDateString()])->assertOk();

        foreach (['check_in.created', 'guest.added', 'check_in.completed', 'check_in.checked_out'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'hotel_id' => $this->hotel->id]);
        }

        $completed = \App\Models\AuditLog::where('action', 'check_in.completed')->first();
        $this->assertSame($this->receptionist->id, $completed->actor_id);

        $out = \App\Models\AuditLog::where('action', 'check_in.checked_out')->first();
        $this->assertSame($this->receptionist2->id, $out->actor_id, 'Le départ doit être imputé au second agent.');
    }
}
