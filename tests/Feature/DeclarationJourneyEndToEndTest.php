<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\Room;
use App\Models\User;
use App\Models\WhatsappSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Le parcours complet de la déclaration, d'un bout à l'autre.
 *
 * ── Pourquoi ce fichier, alors que chaque étape est déjà testée ─────────
 *
 * La suite couvre très bien les étapes PRISES UNE À UNE : création, ajout de
 * voyageur, finalisation, départ, et une longue liste d'invariants de
 * concurrence (HotelJourneyIntegrityTest, CheckInFlowTest). Elle couvre aussi
 * le portail autorité de son côté (AuthorityJourneyIntegrityTest).
 *
 * Ce qu'aucune ne couvrait, c'est la COUTURE entre les deux : qu'un voyageur
 * enregistré au comptoir devienne effectivement visible de l'autorité, et
 * qu'une fiche parte. C'est pourtant la promesse même du produit — le reste
 * n'est que la mécanique qui la sert.
 *
 * Un test par étape peut être vert partout pendant que le raccordement est
 * cassé : chacun vérifie son maillon, aucun ne tire sur la chaîne.
 *
 * ── Comment il est écrit ────────────────────────────────────────────────
 *
 * Une seule séquence, dans l'ordre réel, chaque étape s'appuyant sur l'état
 * laissé par la précédente — comme un poste de réception le vit. On passe par
 * les ENDPOINTS HTTP, pas par les services : c'est la surface que le produit
 * expose réellement, et elle embarque au passage l'authentification, le
 * cloisonnement locataire et les autorisations.
 *
 * Données strictement synthétiques.
 */
class DeclarationJourneyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $reception;

    private const GOUVERNORAT = 'Sousse';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21612345678@c.us',
            'whatsapp.worker_secret' => 'test-secret',
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            'whatsapp.guard.cutover_at' => '2000-01-01T00:00:00+00:00',
        ]);

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 10]);
        HotelAddress::create([
            'hotel_id' => $this->hotel->id,
            'line1' => 'Avenue synthétique',
            'city' => 'Sousse',
            'governorate' => self::GOUVERNORAT,
            'is_primary' => true,
        ]);

        $this->reception = User::factory()->hotelAdmin($this->hotel)->create();
    }

    private function officer(string $type, ?string $governorate): User
    {
        $org = AuthorityOrganization::create([
            'name' => "Organisation {$type} synthétique",
            'type' => $type,
            'governorate' => $governorate,
            'is_active' => true,
        ]);

        $officer = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $officer->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );
        $officer->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $officer;
    }

    public function test_a_traveller_registered_at_the_desk_reaches_the_authority(): void
    {
        // ── 1. La réception ouvre un séjour sur une chambre libre ────────────
        $room = Room::factory()->for($this->hotel)->create(['number' => '404']);

        $libres = $this->actingAs($this->reception)
            ->getJson('/api/v1/hotel/rooms/availability?from='.today()->toDateString()
                .'&to='.today()->addDay()->toDateString())
            ->assertOk()
            ->json('data');

        // La chambre doit être proposée : sans cela, l'étape suivante testerait
        // un chemin que la réception n'aurait jamais pu emprunter.
        $this->assertSame(
            'free',
            collect($libres)->firstWhere('number', '404')['state'] ?? null,
            'la chambre neuve doit être proposée comme libre',
        );

        $stayId = $this->actingAs($this->reception)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date' => today()->toDateString(),
                'expected_check_out_date' => today()->addDay()->toDateString(),
                'room_id' => $room->id,
            ])
            ->assertCreated()
            ->json('data.id');

        // ── 2. Deux voyageurs : le principal et un accompagnant ──────────────
        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/guests", [
                'first_name' => 'Foulen', 'last_name' => 'EL FOULANI',
                'date_of_birth' => '1988-04-12', 'sex' => 'M', 'nationality_code' => 'TUN',
                'is_primary' => true,
                'document' => ['type' => 'passport', 'document_number' => 'E2E0000001', 'issuing_country_code' => 'TUN'],
            ])
            ->assertCreated();

        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/guests", [
                'first_name' => 'Fatma', 'last_name' => 'EL FOULANI',
                'date_of_birth' => '1990-09-30', 'sex' => 'F', 'nationality_code' => 'TUN',
                'is_primary' => false,
                'document' => ['type' => 'passport', 'document_number' => 'E2E0000002', 'issuing_country_code' => 'TUN'],
            ])
            ->assertCreated();

        // ── 3. Finalisation : c'est ce geste qui vaut déclaration ────────────
        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/complete")
            ->assertOk();

        $this->assertSame('active', CheckIn::find($stayId)->status);

        // Une fiche par voyageur part vers l'autorité. On vérifie la mise en
        // file, pas l'appel réseau : le test ne doit rien envoyer.
        $enFile = WhatsappSendLog::where('hotel_id', $this->hotel->id)->count();
        $this->assertGreaterThanOrEqual(
            1,
            $enFile,
            'la finalisation doit mettre au moins une fiche en file',
        );

        // ── 4. L'autorité doit voir le voyageur ──────────────────────────────
        $ministere = $this->officer('ministry', null);

        $vus = $this->actingAs($ministere)
            ->getJson('/api/v1/authority/search?last_name=EL FOULANI')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $vus, 'les deux voyageurs du séjour doivent être visibles');

        $principal = collect($vus)->firstWhere('first_name', 'Foulen');
        $this->assertNotNull($principal, 'le voyageur principal doit ressortir de la recherche');
        $this->assertSame('E2E0000001', $principal['document_number']);
        $this->assertSame($this->hotel->name, $principal['last_stay']['hotel_name'] ?? null);
        $this->assertSame('active', $principal['last_stay']['status'] ?? null);

        // ── 5. Le départ se répercute sur ce que voit l'autorité ─────────────
        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/checkout", [
                'actual_check_out_date' => today()->toDateString(),
            ])
            ->assertOk();

        $apresDepart = $this->actingAs($ministere)
            ->getJson('/api/v1/authority/search?last_name=EL FOULANI')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            'completed',
            collect($apresDepart)->firstWhere('first_name', 'Foulen')['last_stay']['status'] ?? null,
            "le départ saisi au comptoir doit se voir depuis le portail autorité",
        );
    }

    public function test_the_declaration_reaches_the_right_governorate_and_no_other(): void
    {
        /*
         * Le cloisonnement est déjà testé sur des données préparées à la main.
         * Ici il est vérifié sur un séjour qui vient de PARCOURIR le produit :
         * c'est le seul moyen de s'assurer que l'établissement, son adresse et
         * le séjour se relient bien comme le portail l'attend.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '405']);

        $stayId = $this->actingAs($this->reception)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date' => today()->toDateString(),
                'expected_check_out_date' => today()->addDay()->toDateString(),
                'room_id' => $room->id,
            ])->assertCreated()->json('data.id');

        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/guests", [
                'first_name' => 'Salah', 'last_name' => 'ZONESYNTH',
                'date_of_birth' => '1979-02-02', 'sex' => 'M', 'nationality_code' => 'TUN',
                'is_primary' => true,
                'document' => ['type' => 'passport', 'document_number' => 'E2E0000003', 'issuing_country_code' => 'TUN'],
            ])->assertCreated();

        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/complete")->assertOk();

        $bonPoste = $this->officer('police', self::GOUVERNORAT);
        $autrePoste = $this->officer('police', 'Gabès');

        $this->assertCount(
            1,
            $this->actingAs($bonPoste)->getJson('/api/v1/authority/search?last_name=ZONESYNTH')
                ->assertOk()->json('data'),
            'le poste du gouvernorat doit voir la déclaration',
        );

        $this->assertCount(
            0,
            $this->actingAs($autrePoste)->getJson('/api/v1/authority/search?last_name=ZONESYNTH')
                ->assertOk()->json('data'),
            "un poste d'un autre gouvernorat ne doit rien voir",
        );
    }

    public function test_a_stay_left_unfinished_is_never_declared(): void
    {
        /*
         * Le pendant du premier test, et la garantie qui compte pour le
         * voyageur : tant que la réception n'a pas finalisé, rien ne part et
         * rien n'est visible. Un brouillon est une saisie en cours, pas une
         * déclaration.
         */
        $room = Room::factory()->for($this->hotel)->create(['number' => '406']);

        $stayId = $this->actingAs($this->reception)
            ->postJson('/api/v1/hotel/check-ins', [
                'check_in_date' => today()->toDateString(),
                'expected_check_out_date' => today()->addDay()->toDateString(),
                'room_id' => $room->id,
            ])->assertCreated()->json('data.id');

        $this->actingAs($this->reception)
            ->postJson("/api/v1/hotel/check-ins/{$stayId}/guests", [
                'first_name' => 'Brouillon', 'last_name' => 'JAMAISDECLARE',
                'date_of_birth' => '1995-05-05', 'sex' => 'F', 'nationality_code' => 'TUN',
                'is_primary' => true,
                'document' => ['type' => 'passport', 'document_number' => 'E2E0000004', 'issuing_country_code' => 'TUN'],
            ])->assertCreated();

        $this->assertSame('draft', CheckIn::find($stayId)->status);
        $this->assertSame(0, WhatsappSendLog::where('hotel_id', $this->hotel->id)->count());

        $ministere = $this->officer('ministry', null);

        $this->assertCount(
            0,
            $this->actingAs($ministere)->getJson('/api/v1/authority/search?last_name=JAMAISDECLARE')
                ->assertOk()->json('data'),
            "un séjour non finalisé ne doit pas apparaître au portail autorité",
        );
    }
}
