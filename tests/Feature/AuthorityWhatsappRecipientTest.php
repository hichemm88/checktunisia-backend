<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 de l'envoi direct aux agents : numéro WhatsApp + « reçoit les fiches »
 * sur le profil agent, renseignés UNIQUEMENT par l'admin plateforme.
 */
class AuthorityWhatsappRecipientTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AuthorityOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->platformAdmin()->create();
        $this->org = AuthorityOrganization::create([
            'name' => 'Poste Tunis Medina', 'type' => 'police', 'governorate' => 'Tunis', 'is_active' => true,
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Ali', 'last_name' => 'Ben Amor',
            'email' => 'ali.police@example.tn',
            'password' => 'Str0ng!Passw0rd#',
            'organization_id' => $this->org->id,
        ], $over);
    }

    public function test_admin_creates_agent_with_whatsapp_number_and_flag(): void
    {
        $res = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/authority-users', $this->payload([
                'whatsapp_number' => '+216 20 123 456',
                'receives_whatsapp_fiches' => true,
            ]))
            ->assertCreated();

        $userId = $res->json('data.id');
        // Numéro normalisé en chiffres internationaux.
        $this->assertDatabaseHas('authority_user_profiles', [
            'user_id' => $userId,
            'whatsapp_number' => '21620123456',
            'receives_whatsapp_fiches' => true,
        ]);
    }

    public function test_receives_flag_requires_a_number(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/authority-users', $this->payload([
                'receives_whatsapp_fiches' => true,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'WHATSAPP_REQUIRED');
    }

    public function test_invalid_number_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/authority-users', $this->payload([
                'whatsapp_number' => '12', // trop court
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'INVALID_WHATSAPP');
    }

    public function test_admin_can_edit_number_and_flag_afterwards(): void
    {
        $userId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/authority-users', $this->payload())
            ->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/admin/authority-users/{$userId}", [
                'whatsapp_number' => '216 99 888 777',
                'receives_whatsapp_fiches' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('authority_user_profiles', [
            'user_id' => $userId, 'whatsapp_number' => '21699888777', 'receives_whatsapp_fiches' => true,
        ]);
    }
}
