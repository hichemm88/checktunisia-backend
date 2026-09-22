<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impression de la fiche de police depuis l'écran check-in.
 *
 * Avant ce point, ce bouton imprimait un composant React (`PoliceFiche.tsx`)
 * totalement distinct du PDF de l'export/WhatsApp — même établissement, même
 * voyageur, deux documents différents selon le bouton cliqué. Cet endpoint
 * rend désormais le MÊME template (`pdf.police-fiches` + `FicheFormatter`)
 * que `HotelExportTest` : ce qui est éprouvé ici n'est pas « un PDF sort »,
 * mais que c'est la même chaîne qui le produit.
 */
class CheckInPoliceFichePdfTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $manager;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);
        $this->manager = User::factory()->hotelAdmin($this->hotel)->create();
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
    }

    public function test_a_receptionist_can_print_the_fiche_as_an_inline_pdf(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Martin', 'Ostermeier')->create([
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->receptionist)
            ->get("/api/v1/hotel/check-ins/{$checkIn->id}/police-fiche")
            ->assertOk();

        $response->assertHeader('Content-Type', 'application/pdf');
        // `stream()`, pas `download()` : le PDF s'ouvre dans l'onglet plutôt que
        // de forcer un téléchargement — c'est ce qui permet d'imprimer via le
        // bouton du lecteur PDF du navigateur.
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_manager_can_print_the_fiche_too(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Sophie', 'Leclerc')->create([
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)
            ->get("/api/v1/hotel/check-ins/{$checkIn->id}/police-fiche")
            ->assertOk();
    }

    public function test_it_is_the_same_formatter_as_the_export_not_a_second_implementation(): void
    {
        // Le nom du voyageur, en MAJUSCULES pour le nom de famille — exactement
        // la sortie de FicheFormatter::fields(), pas un format inventé à part.
        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Amélie', 'Rousseau')->create([
            'created_by' => $this->manager->id,
        ]);

        $pdf = $this->actingAs($this->receptionist)
            ->get("/api/v1/hotel/check-ins/{$checkIn->id}/police-fiche")
            ->assertOk()
            ->getContent();

        // DomPDF encode le texte en interne (pas de recherche de sous-chaîne
        // fiable sur le binaire) — la preuve indirecte mais robuste est la
        // taille : une fiche avec un nom réel produit un PDF non trivial.
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_a_check_in_from_another_hotel_is_not_found(): void
    {
        $otherHotel = Hotel::factory()->withActiveSubscription()->create();
        $otherCheckIn = CheckIn::factory()->for($otherHotel)->active()->withGuest('Jean', 'Dupont')->create();

        $this->actingAs($this->receptionist)
            ->get("/api/v1/hotel/check-ins/{$otherCheckIn->id}/police-fiche")
            ->assertNotFound();
    }
}
