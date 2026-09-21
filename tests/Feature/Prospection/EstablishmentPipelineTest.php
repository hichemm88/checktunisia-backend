<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EstablishmentPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(): array
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $user->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_changing_status_creates_an_append_only_action(): void
    {
        $headers = $this->authHeader();
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'a_contacter']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'contacte'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacte');

        $this->assertSame('contacte', $establishment->fresh()->status);
        $this->assertSame(1, ProspectionAction::where('type', 'changement_statut')->count());
        $this->assertSame(
            'a_contacter → contacte',
            ProspectionAction::where('type', 'changement_statut')->first()->content,
        );
    }

    public function test_moving_to_contacte_defaults_next_action_to_four_days_out(): void
    {
        $headers = $this->authHeader();
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'a_contacter']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'contacte'])
            ->assertOk();

        $establishment->refresh();
        $this->assertNotNull($establishment->next_action_at);
        $this->assertEqualsWithDelta(
            now()->addDays(4)->timestamp,
            $establishment->next_action_at->timestamp,
            5,
        );
    }

    public function test_moving_to_demo_planifiee_requires_a_date(): void
    {
        $headers = $this->authHeader();
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'contacte']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'demo_planifiee'])
            ->assertStatus(422);

        $this->assertSame('contacte', $establishment->fresh()->status);
    }

    public function test_moving_to_demo_planifiee_with_a_date_succeeds(): void
    {
        $headers = $this->authHeader();
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'contacte']);
        $demoAt = now()->addDays(2)->toIso8601String();

        $this->withHeaders($headers)
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", [
                'status' => 'demo_planifiee',
                'next_action_at' => $demoAt,
            ])
            ->assertOk();

        $this->assertSame('demo_planifiee', $establishment->fresh()->status);
    }

    public function test_a_terminal_status_clears_the_next_action_date(): void
    {
        $headers = $this->authHeader();
        $establishment = Establishment::create([
            'name' => 'Dar Zaghouan', 'status' => 'relance', 'next_action_at' => now()->addDay(),
        ]);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'sans_reponse'])
            ->assertOk();

        $this->assertNull($establishment->fresh()->next_action_at);
    }

    public function test_today_endpoint_lists_overdue_and_due_establishments_first(): void
    {
        $headers = $this->authHeader();
        Establishment::create(['name' => 'En retard', 'status' => 'relance', 'next_action_at' => now()->subDays(2)]);
        Establishment::create(['name' => 'Aujourdhui', 'status' => 'relance', 'next_action_at' => now()]);
        Establishment::create(['name' => 'Plus tard', 'status' => 'relance', 'next_action_at' => now()->addWeek()]);
        Establishment::create(['name' => 'Sans relance', 'status' => 'a_contacter']);

        $response = $this->withHeaders($headers)->getJson('/api/v1/prospection/establishments/today')->assertOk();

        $names = collect($response->json('data.relances'))->pluck('name');
        $this->assertEqualsCanonicalizing(['En retard', 'Aujourdhui'], $names->all());
    }
}
