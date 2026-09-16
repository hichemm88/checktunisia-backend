<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * Admin > Partenaires (API publique v1) : émission/révocation des clés,
 * jamais d'exposition du clair après l'émission — vue platform_admin
 * (require role:platform_admin + admin.2fa, comme le reste de /api/v1/admin/*).
 */
class AdminPartnerManagementTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private function platformAdmin(): User
    {
        // UserFactory::platformAdmin() already confirms 2FA (two_factor_confirmed_at),
        // mirroring PlanEntitlementsTest / other admin.2fa-gated feature tests.
        return User::factory()->platformAdmin()->create();
    }

    public function test_issuing_a_key_returns_the_plaintext_once_and_show_index_never_expose_it(): void
    {
        $admin = $this->platformAdmin();
        $partner = $this->makePartner();

        $issued = $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$partner->id}/keys", ['mode' => 'live'])
            ->assertCreated();

        $plaintext = $issued->json('data.plaintext');
        $this->assertStringStartsWith('qyd_live_', $plaintext);
        $keyId = $issued->json('data.id');

        // show
        $show = $this->actingAs($admin)
            ->getJson("/api/v1/admin/partners/{$partner->id}")
            ->assertOk();
        $this->assertStringNotContainsString($plaintext, $show->content());
        $this->assertArrayNotHasKey('plaintext', $show->json('data.keys.0') ?? []);
        $this->assertArrayNotHasKey('key_hash', $show->json('data.keys.0') ?? []);

        // index
        $index = $this->actingAs($admin)
            ->getJson('/api/v1/admin/partners')
            ->assertOk();
        $this->assertStringNotContainsString($plaintext, $index->content());

        // Sanity: the key really was persisted (by id), just never re-exposed in the clear.
        $this->assertDatabaseHas('api_keys', ['id' => $keyId, 'partner_id' => $partner->id]);
    }

    public function test_revoking_a_key_immediately_makes_it_unusable_against_v1(): void
    {
        $this->seedWidgetJwtSecret();
        $admin = $this->platformAdmin();
        $partner = $this->makePartner();

        $issued = $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$partner->id}/keys", ['mode' => 'live'])
            ->assertCreated();
        $plaintext = $issued->json('data.plaintext');
        $keyId = $issued->json('data.id');

        // Works before revocation.
        $this->withHeaders($this->bearer($plaintext))
            ->getJson('/v1/establishments')
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$partner->id}/keys/{$keyId}/revoke")
            ->assertOk()
            ->assertJsonPath('data.id', $keyId);

        $this->assertDatabaseHas('api_keys', ['id' => $keyId]);
        $this->assertNotNull(\App\Models\ApiKey::find($keyId)->revoked_at);

        $this->withHeaders($this->bearer($plaintext))
            ->getJson('/v1/establishments')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }
}
