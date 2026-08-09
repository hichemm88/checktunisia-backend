<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deux réceptions qui enregistrent le même passeport en même temps.
 *
 * Le cas se produit vraiment : deux postes au comptoir, ou un double clic
 * suivi d'un rejeu réseau. Le rapprochement fait un SELECT puis un INSERT ;
 * entre les deux, l'autre requête a pu insérer la même pièce. L'index
 * d'unicité (type, numéro, pays) fait alors son travail — mais la violation
 * remontait brute : erreur 500, aucun message, et une réception qui ne sait
 * pas si le voyageur a été enregistré ou non.
 *
 * Ce que ces tests exigent : un refus MÉTIER lisible (422), jamais une
 * erreur serveur — et aucune donnée à moitié écrite derrière.
 */
class TravelDocumentConflictTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private User $staff;
    private CheckIn $checkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->staff = User::factory()->hotelAdmin($this->hotel)->create();
        $this->checkIn = CheckIn::factory()->create([
            'hotel_id'   => $this->hotel->id,
            'status'     => 'draft',
            'created_by' => $this->staff->id,
        ]);
    }

    /**
     * Simule l'entrelacement exact : la pièce est insérée par « l'autre
     * requête » juste après que celle-ci a constaté son absence, donc entre
     * le SELECT et l'INSERT.
     */
    private function insertConcurrentlyOnCreating(array $doc): void
    {
        $fired = false;

        TravelDocument::creating(function () use (&$fired, $doc) {
            if ($fired) {
                return;
            }
            $fired = true;

            $other = Guest::create([
                'first_name' => 'Autre', 'last_name' => 'SAISIE',
                'date_of_birth' => '1990-01-01', 'sex' => 'M', 'nationality_code' => 'TUN',
            ]);

            DB::table('travel_documents')->insert([
                'id'                   => Str::uuid()->toString(),
                'guest_id'             => $other->id,
                'type'                 => $doc['type'],
                'document_number'      => $doc['document_number'],
                'issuing_country_code' => $doc['issuing_country_code'],
                'is_verified'          => true,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        });
    }

    private function addGuest(array $document): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)
            ->postJson("/api/v1/hotel/check-ins/{$this->checkIn->id}/guests", [
                'first_name'       => 'Amine',
                'last_name'        => 'BEN SALAH',
                'date_of_birth'    => '1988-04-12',
                'sex'              => 'M',
                'nationality_code' => 'TUN',
                'document'         => $document,
            ]);
    }

    public function test_a_concurrent_duplicate_document_is_refused_with_a_business_error(): void
    {
        $doc = [
            'type'                 => 'passport',
            'document_number'      => 'AB123456',
            'issuing_country_code' => 'TUN',
        ];

        $this->insertConcurrentlyOnCreating($doc);

        $response = $this->addGuest($doc);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'DOCUMENT_ALREADY_USED');
        $this->assertNotEmpty($response->json('errors.0.message'), 'la réception doit lire une cause');
    }

    /**
     * Rien à moitié écrit : la fiche ne garde pas un voyageur fantôme.
     *
     * On ne vérifie pas ici ce que devient la pièce de « l'autre saisie » :
     * dans ce banc d'essai les deux écritures partagent la même transaction,
     * l'annulation emporte donc les deux. En production elles vivent sur deux
     * connexions distinctes et la première reste committée. Ce que ce test
     * garantit — et qui vaut dans les deux cas — c'est que la requête refusée
     * ne laisse RIEN derrière elle.
     */
    public function test_a_refused_concurrent_add_leaves_no_half_written_guest(): void
    {
        $doc = [
            'type'                 => 'passport',
            'document_number'      => 'CD987654',
            'issuing_country_code' => 'TUN',
        ];

        $this->insertConcurrentlyOnCreating($doc);
        $this->addGuest($doc)->assertStatus(422);

        $this->assertSame(0, $this->checkIn->fresh()->guests()->count(), 'aucun voyageur rattaché au séjour');
        $this->assertSame(
            0,
            TravelDocument::where('document_number', 'CD987654')->whereHas('guest', fn ($q) => $q->where('last_name', 'BEN SALAH'))->count(),
            'la saisie refusée n\'a rien enregistré',
        );
    }

    /** Non-régression : sans course, l'ajout normal reste un 201. */
    public function test_a_normal_add_is_untouched(): void
    {
        $this->addGuest([
            'type'                 => 'passport',
            'document_number'      => 'EF456123',
            'issuing_country_code' => 'TUN',
        ])->assertCreated();

        $this->assertSame(1, $this->checkIn->fresh()->guests()->count());
    }

    /**
     * Non-régression : ré-enregistrer le MÊME voyageur avec la même pièce
     * (double clic sans concurrence) reste une opération normale — c'est le
     * rapprochement qui joue, pas un conflit.
     */
    public function test_re_adding_the_same_traveller_is_not_a_conflict(): void
    {
        $doc = [
            'type'                 => 'passport',
            'document_number'      => 'GH789456',
            'issuing_country_code' => 'TUN',
        ];

        $this->addGuest($doc)->assertCreated();
        $this->addGuest($doc)->assertCreated();

        $this->assertSame(1, $this->checkIn->fresh()->guests()->count(), 'le même voyageur, une seule fois');
    }
}
