<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\MessageTemplate;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MessageTemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(string $role = 'admin'): array
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => "{$role}@qayed.tn",
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => $role,
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $user->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_index_only_returns_active_templates_by_default(): void
    {
        MessageTemplate::create(['name' => 'Actif', 'body' => 'x', 'active' => true]);
        MessageTemplate::create(['name' => 'Inactif', 'body' => 'x', 'active' => false]);

        $response = $this->withHeaders($this->authHeader('membre'))
            ->getJson('/api/v1/prospection/message-templates')
            ->assertOk();

        $this->assertSame(['Actif'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_index_with_all_and_admin_includes_inactive_templates(): void
    {
        MessageTemplate::create(['name' => 'Actif', 'body' => 'x', 'active' => true]);
        MessageTemplate::create(['name' => 'Inactif', 'body' => 'x', 'active' => false]);

        $response = $this->withHeaders($this->authHeader('admin'))
            ->getJson('/api/v1/prospection/message-templates?all=1')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Actif', 'Inactif'],
            collect($response->json('data'))->pluck('name')->all(),
        );
    }

    public function test_index_with_all_from_a_member_still_excludes_inactive_templates(): void
    {
        MessageTemplate::create(['name' => 'Actif', 'body' => 'x', 'active' => true]);
        MessageTemplate::create(['name' => 'Inactif', 'body' => 'x', 'active' => false]);

        $response = $this->withHeaders($this->authHeader('membre'))
            ->getJson('/api/v1/prospection/message-templates?all=1')
            ->assertOk();

        $this->assertSame(['Actif'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_admin_can_create_a_template(): void
    {
        $this->withHeaders($this->authHeader('admin'))
            ->postJson('/api/v1/prospection/message-templates', [
                'name' => 'Relance froide',
                'body' => 'Bonjour {prenom}, un petit rappel pour {etablissement}.',
                'segment' => 'hotel',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Relance froide');

        $this->assertSame(1, MessageTemplate::count());
    }

    public function test_a_member_cannot_create_a_template(): void
    {
        $this->withHeaders($this->authHeader('membre'))
            ->postJson('/api/v1/prospection/message-templates', ['name' => 'x', 'body' => 'x'])
            ->assertStatus(403);

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_admin_can_deactivate_a_template(): void
    {
        $template = MessageTemplate::create(['name' => 'Actif', 'body' => 'x', 'active' => true]);

        $this->withHeaders($this->authHeader('admin'))
            ->patchJson("/api/v1/prospection/message-templates/{$template->id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertFalse($template->fresh()->active);
    }

    public function test_admin_can_delete_a_template(): void
    {
        $template = MessageTemplate::create(['name' => 'À supprimer', 'body' => 'x']);

        $this->withHeaders($this->authHeader('admin'))
            ->deleteJson("/api/v1/prospection/message-templates/{$template->id}")
            ->assertOk();

        $this->assertSame(0, MessageTemplate::count());
    }

    public function test_a_member_cannot_delete_a_template(): void
    {
        $template = MessageTemplate::create(['name' => 'Protégé', 'body' => 'x']);

        $this->withHeaders($this->authHeader('membre'))
            ->deleteJson("/api/v1/prospection/message-templates/{$template->id}")
            ->assertStatus(403);

        $this->assertSame(1, MessageTemplate::count());
    }
}
