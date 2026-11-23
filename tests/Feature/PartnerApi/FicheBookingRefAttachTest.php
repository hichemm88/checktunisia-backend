<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Hotel;
use App\Models\PartnerWebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerFiches;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * API v1.2 (lot de nuit du 23/11/2026) — rattacher une fiche existante (saisie
 * dans Qayed) à une réservation partenaire, puis la rouvrir en complément au
 * lieu d'en créer une seconde ; abonnement d'un endpoint aux événements.
 */
class FicheBookingRefAttachTest extends TestCase
{
    use BuildsPartnerFiches;
    use InteractsWithPartnerApi;
    use RefreshDatabase;

    private function patchFiche(array $fixture, string $ficheId, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->bearer($fixture['plaintext']))->patchJson('/v1/fiches/'.$ficheId, $body);
    }

    public function test_a_native_fiche_without_booking_ref_can_be_attached_then_reopened_in_amend_mode(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $native = $this->makeNativeFiche($fixture['hotel']);

        $this->patchFiche($fixture, $native->id, ['booking_ref' => 'DIARNA-777'])
            ->assertOk()
            ->assertJsonPath('fiche_id', $native->id)
            ->assertJsonPath('booking_ref', 'DIARNA-777');

        $native->refresh();
        $this->assertSame('DIARNA-777', $native->booking_reference);
        $this->assertSame($fixture['partner']->id, $native->metadata['partner_id']);
        $this->assertNotSame('api', $native->metadata['source'] ?? null, 'L\'origine reste « saisie dans Qayed ».');

        // Rejouer le même rattachement est sans effet (idempotent).
        $this->patchFiche($fixture, $native->id, ['booking_ref' => 'DIARNA-777'])->assertOk();

        $created = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'DIARNA-777'))
            ->assertSuccessful();

        $this->assertSame('amend', $created->json('mode'));
        $this->assertSame(1, CheckIn::where('hotel_id', $fixture['hotel']->id)->count(), 'Aucune seconde fiche créée.');
        $this->assertSame($native->id, FicheSession::findOrFail($created->json('session_id'))->check_in_id);

        // La fiche rattachée est désormais lisible par le partenaire, toujours marquée « qayed ».
        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiches/'.$native->id)->assertOk()->assertJsonPath('booking_ref', 'DIARNA-777');
        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiches?establishment_id='.$fixture['hotel']->id)
            ->assertOk()->assertJsonPath('data.0.source', 'qayed')->assertJsonPath('data.0.booking_ref', 'DIARNA-777');
    }

    public function test_refused_when_another_fiche_of_the_same_establishment_carries_the_booking_ref(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $this->makeNativeFiche($fixture['hotel'], ['booking_reference' => 'DIARNA-888']);
        $target = $this->makeNativeFiche($fixture['hotel']);

        $this->patchFiche($fixture, $target->id, ['booking_ref' => 'DIARNA-888'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'booking_ref_conflict');

        $this->assertNull($target->fresh()->booking_reference);
    }

    public function test_same_booking_ref_in_another_establishment_is_not_a_conflict(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $this->makeNativeFiche(Hotel::factory()->create(), ['booking_reference' => 'DIARNA-999']);
        $target = $this->makeNativeFiche($fixture['hotel']);

        $this->patchFiche($fixture, $target->id, ['booking_ref' => 'DIARNA-999'])->assertOk();
    }

    public function test_a_fiche_that_already_has_a_booking_ref_cannot_be_reattached(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $native = $this->makeNativeFiche($fixture['hotel'], ['booking_reference' => 'BOOKING-COM-1']);
        $api = $this->makeApiFiche($fixture['hotel'], $fixture['partner'], 'DIARNA-1');

        $this->patchFiche($fixture, $native->id, ['booking_ref' => 'DIARNA-2'])
            ->assertStatus(409)->assertJsonPath('error.code', 'booking_ref_already_set');
        $this->patchFiche($fixture, $api->id, ['booking_ref' => 'DIARNA-3'])
            ->assertStatus(409)->assertJsonPath('error.code', 'booking_ref_already_set');

        $this->assertSame('BOOKING-COM-1', $native->fresh()->booking_reference);
        $this->assertSame('DIARNA-1', $api->fresh()->booking_reference);
    }

    public function test_cannot_attach_a_fiche_of_an_unlinked_establishment_or_with_a_test_key(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $elsewhere = $this->makeNativeFiche(Hotel::factory()->create());
        $this->patchFiche($fixture, $elsewhere->id, ['booking_ref' => 'X-1'])->assertNotFound();

        // Clé de test : les fiches réelles restent invisibles, comme dans la liste.
        $native = $this->makeNativeFiche($fixture['hotel']);
        ['plaintext' => $test] = $this->issuePartnerKey($fixture['partner'], ApiKey::MODE_TEST);
        $this->withHeaders($this->bearer($test))->patchJson('/v1/fiches/'.$native->id, ['booking_ref' => 'X-2'])->assertNotFound();
        $this->assertNull($native->fresh()->booking_reference);
    }

    public function test_cannot_attach_a_draft_or_a_fiche_already_attached_to_another_partner(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $draft = $this->makeNativeFiche($fixture['hotel'], ['status' => 'draft', 'completed_at' => null, 'completed_by' => null]);
        $this->patchFiche($fixture, $draft->id, ['booking_ref' => 'D-1'])->assertNotFound();

        $other = $this->makePartner();
        $theirs = $this->makeNativeFiche($fixture['hotel'], ['metadata' => ['partner_id' => $other->id]]);
        $this->patchFiche($fixture, $theirs->id, ['booking_ref' => 'D-2'])->assertNotFound();
    }

    public function test_fiche_detail_lists_guests_with_first_and_last_name_only(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $primary = \App\Models\Guest::factory()->named('Amel', 'Ben Salah')->create();
        $fiche = $this->makeApiFiche($fixture['hotel'], $fixture['partner'], 'DIARNA-G1', false, [], $primary);
        $second = $this->attachGuest($fiche, \App\Models\Guest::factory()->named('John', 'Smith')->create());
        $this->giveDocument($primary, 'TN12345678');
        $this->giveDocument($second, 'GB98765432');

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiches/'.$fiche->id)
            ->assertOk();

        $this->assertSame([
            ['first_name' => 'Amel', 'last_name' => 'Ben Salah'],
            ['first_name' => 'John', 'last_name' => 'Smith'],
        ], $response->json('guests'));
        $body = $response->getContent();
        $this->assertStringNotContainsString('TN12345678', $body);
        $this->assertStringNotContainsString('date_of_birth', $body);
    }

    public function test_admin_can_subscribe_an_existing_endpoint_to_the_delivery_events(): void
    {
        $partner = $this->makePartner();
        $endpoint = $this->makeWebhookEndpoint($partner);
        $admin = User::factory()->platformAdmin()->create();

        $events = ['fiche.submitted', 'fiche.failed', 'session.expired', 'fiche.delivered', 'fiche.delivery_failed'];
        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/partners/{$partner->id}/webhooks/{$endpoint->id}", ['events' => $events])
            ->assertOk()
            ->assertJsonPath('data.events', $events);
        $this->assertSame($events, PartnerWebhookEndpoint::findOrFail($endpoint->id)->events);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/partners/{$partner->id}/webhooks/{$endpoint->id}", ['events' => ['fiche.unknown']])
            ->assertStatus(422);
    }
}
