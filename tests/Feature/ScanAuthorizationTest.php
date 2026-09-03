<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La porte consultée par les fonctions serverless avant tout appel payé.
 *
 * ── Ce qu'elle remplace ─────────────────────────────────────────────────
 *
 * `/api/scan/cin` et `/api/scan/mrz` appellent Claude vision, facturé à
 * l'appel. Elles ne vérifiaient que la FORME du jeton porteur : « Bearer x »
 * suffisait. Le budget Anthropic était donc ouvert à quiconque connaissait
 * l'URL — publique, sur le domaine de l'application — et `propertyId` étant
 * choisi par l'appelant, la dépense était imputable à l'établissement de son
 * choix.
 *
 * Cet endpoint répond à une seule question : « ce compte peut-il scanner pour
 * cet établissement ? ». Les tests ci-dessous couvrent les trois réponses
 * possibles, et le fait qu'aucune ne divulgue l'existence d'un établissement.
 */
class ScanAuthorizationTest extends TestCase
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

    public function test_a_staff_member_is_authorized_for_their_own_property(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson("/api/v1/hotel/scan-authorization?property_id={$this->hotel->id}")
            ->assertOk()
            ->assertJsonPath('data.property_id', $this->hotel->id)
            ->assertJsonPath('data.user_id', $this->receptionist->id);
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        // Le cas qui était grand ouvert côté serverless.
        $this->getJson("/api/v1/hotel/scan-authorization?property_id={$this->hotel->id}")
            ->assertUnauthorized();
    }

    public function test_a_foreign_property_is_refused(): void
    {
        $foreign = Hotel::factory()->withActiveSubscription()->create();

        $this->actingAs($this->receptionist)
            ->getJson("/api/v1/hotel/scan-authorization?property_id={$foreign->id}")
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'PROPERTY_NOT_ACCESSIBLE');
    }

    public function test_an_unknown_property_answers_like_a_foreign_one(): void
    {
        // Même code, même statut : la route ne doit pas devenir un oracle
        // d'existence sur le parc d'établissements.
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/hotel/scan-authorization?property_id=00000000-0000-4000-8000-000000000000')
            ->assertForbidden()
            ->assertJsonPath('errors.0.code', 'PROPERTY_NOT_ACCESSIBLE');
    }

    public function test_a_missing_property_id_is_a_validation_error(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/hotel/scan-authorization')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'property_id');
    }

    public function test_an_authority_account_cannot_use_the_scan_budget(): void
    {
        // La route vit dans le groupe hôtelier : un compte autorité n'a rien à
        // y faire, et surtout rien à y dépenser.
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/hotel/scan-authorization?property_id={$this->hotel->id}")
            ->assertForbidden();
    }
}
