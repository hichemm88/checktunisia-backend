<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\User;
use App\Services\PartnerApi\PartnerIntegrationActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'acteur système créé par PartnerIntegrationActor pour le Partner API/widget
 * (un `User` `is_system_actor=true` par organisation) n'est pas un membre
 * d'équipe qu'un owner gère depuis Équipe/Paramètres — un owner a confondu les
 * deux en production le 2026-09-16 et a failli le supprimer depuis cet écran,
 * ce qui n'aurait fait que le désactiver/soft-delete sans l'empêcher d'être
 * réutilisé tel quel (inactif) par le prochain appel API (voir
 * PartnerIntegrationActor::forOrganization, `withTrashed()`).
 */
class HotelUserSystemActorTest extends TestCase
{
    use RefreshDatabase;

    private function makeOwnerWithHotel(): array
    {
        $org = Organization::create([
            'name' => 'Org Test', 'entity_type' => 'company',
            'contact_email' => 'owner@example.test', 'status' => 'active',
        ]);
        $hotel = Hotel::factory()->withActiveSubscription()->create([
            'organization_id' => $org->id, 'setup_completed_at' => now(),
        ]);
        $owner = User::factory()->create([
            'organization_id' => $org->id, 'role_org' => 'owner', 'email' => $org->contact_email,
        ]);
        $owner->assignRole('hotel_admin');
        $owner->hotels()->attach($hotel->id, ['granted_at' => now()]);

        return compact('org', 'hotel', 'owner');
    }

    public function test_the_system_actor_never_appears_in_the_team_list(): void
    {
        ['org' => $org, 'hotel' => $hotel, 'owner' => $owner] = $this->makeOwnerWithHotel();
        $actor = app(PartnerIntegrationActor::class)->forOrganization($org);
        app(PartnerIntegrationActor::class)->ensureAttachedTo($actor, $hotel);

        $response = $this->actingAs($owner)->getJson('/api/v1/hotel/users')->assertOk();

        $this->assertNotContains($actor->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_the_system_actor_cannot_be_deleted_or_edited_from_the_team_screen(): void
    {
        ['org' => $org, 'hotel' => $hotel, 'owner' => $owner] = $this->makeOwnerWithHotel();
        $actor = app(PartnerIntegrationActor::class)->forOrganization($org);
        app(PartnerIntegrationActor::class)->ensureAttachedTo($actor, $hotel);

        $this->actingAs($owner)->deleteJson("/api/v1/hotel/users/{$actor->id}")->assertStatus(404);
        $this->actingAs($owner)->patchJson("/api/v1/hotel/users/{$actor->id}", ['status' => 'inactive'])->assertStatus(404);

        $this->assertSame('active', $actor->fresh()->status);
        $this->assertNull($actor->fresh()->deleted_at);
    }
}
