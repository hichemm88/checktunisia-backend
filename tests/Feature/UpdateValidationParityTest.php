<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une règle de validation ne doit pas s'affaiblir à la MODIFICATION.
 *
 * ── La faille, et pourquoi elle passe inaperçue ─────────────────────────
 *
 * Les règles de création sont écrites en même temps que le formulaire, donc
 * avec le métier en tête : une date de naissance est dans le passé, un départ
 * est après une arrivée. Les règles de modification sont écrites plus tard,
 * en recopiant la liste des champs — et `required|date|before:today` devient
 * `sometimes|date`. La contrainte disparaît sans que personne l'ait décidé.
 *
 * Le formulaire, lui, continue de bien se comporter : il n'envoie jamais ces
 * valeurs-là. La faille n'est donc visible ni à l'écran ni dans les tests de
 * parcours. Elle s'ouvre pour tout ce qui n'est pas le formulaire — un appel
 * d'API, un import, un script de reprise, un client qui se trompe.
 *
 * ── Pourquoi c'est grave ici ────────────────────────────────────────────
 *
 * Ces champs ne sont pas décoratifs. La date de naissance part sur la fiche
 * transmise au poste de police. Les dates de séjour alimentent l'occupation,
 * les quotas de check-in et la facturation. Une durée négative n'est pas une
 * donnée bizarre : c'est un compteur faux, et un document légal faux.
 */
class UpdateValidationParityTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
    }

    // ── Voyageur ─────────────────────────────────────────────────────────────

    public function test_a_guest_birth_date_cannot_be_moved_into_the_future(): void
    {
        $checkIn = $this->draft();

        $guestId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())
            ->assertCreated()
            ->json('data.id');

        // La création refuse déjà cette date. La modification l'acceptait, et
        // la fiche de police partait avec un voyageur né en 2099.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$guestId}", [
                'date_of_birth' => '2099-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'date_of_birth');
    }

    public function test_a_guest_birth_date_can_still_be_corrected_to_a_past_date(): void
    {
        $checkIn = $this->draft();

        $guestId = $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload())
            ->assertCreated()
            ->json('data.id');

        // Le durcissement ne doit pas empêcher la correction d'une faute de
        // frappe, qui est le cas d'usage NORMAL de cet endpoint.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests/{$guestId}", [
                'date_of_birth' => '1988-03-02',
            ])
            ->assertOk();

        $this->assertSame(
            '1988-03-02',
            \App\Models\Guest::find($guestId)->date_of_birth->toDateString(),
        );
    }

    // ── Séjour ───────────────────────────────────────────────────────────────

    public function test_a_stay_cannot_be_made_to_end_before_it_starts(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
            'check_in_date' => '2026-09-10',
            'expected_check_out_date' => '2026-09-14',
        ]);

        // Une durée négative n'est pas une donnée bizarre : c'est une
        // occupation fausse, un quota faux, et une facturation fausse.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}", [
                'expected_check_out_date' => '2026-09-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'expected_check_out_date');
    }

    public function test_a_stay_can_still_be_extended(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
            'check_in_date' => '2026-09-10',
            'expected_check_out_date' => '2026-09-14',
        ]);

        // Prolonger un séjour est l'usage courant de cet endpoint : il doit
        // rester possible, y compris le jour même de l'arrivée.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}", [
                'expected_check_out_date' => '2026-09-20',
            ])
            ->assertOk();

        $this->assertSame(
            '2026-09-20',
            $checkIn->fresh()->expected_check_out_date->toDateString(),
        );
    }

    public function test_occupancy_counts_keep_their_upper_bound_on_update(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);

        // `max:50` / `max:20` existent à la création et disparaissaient à la
        // modification : un séjour à deux milliards d'adultes passait, et
        // faussait toutes les moyennes du tableau de bord.
        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}", ['adults_count' => 2_000_000_000])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'adults_count');

        $this->actingAs($this->receptionist)
            ->patchJson("/api/v1/hotel/check-ins/{$checkIn->id}", ['children_count' => 999])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'children_count');
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function draft(): CheckIn
    {
        return CheckIn::factory()->for($this->hotel)->draft()->create([
            'created_by' => $this->receptionist->id,
        ]);
    }

    /** @return array<string,mixed> */
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
}
