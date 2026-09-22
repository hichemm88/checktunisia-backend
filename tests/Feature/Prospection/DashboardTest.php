<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_funnel_counts_establishments_per_status_including_zero_counts(): void
    {
        Establishment::create(['name' => 'A', 'status' => 'a_contacter']);
        Establishment::create(['name' => 'B', 'status' => 'a_contacter']);
        Establishment::create(['name' => 'C', 'status' => 'client']);
        Establishment::create(['name' => 'Archivée', 'status' => 'contacte', 'archived' => true]);

        $response = $this->withHeaders($this->authHeader())->getJson('/api/v1/prospection/dashboard')->assertOk();

        $funnel = collect($response->json('data.funnel'))->keyBy('status');
        $this->assertSame(2, $funnel['a_contacter']['count']);
        $this->assertSame(1, $funnel['client']['count']);
        // Un statut sans aucun établissement doit rester à 0, pas absent —
        // sinon le graphique de l'écran Dashboard perdrait une colonne.
        $this->assertSame(0, $funnel['demo_faite']['count']);
        // Un établissement archivé ne doit pas polluer l'entonnoir actif.
        $this->assertSame(0, $funnel['contacte']['count']);
        $this->assertSame(3, $response->json('data.total'));
    }

    public function test_by_zone_and_by_priority_group_active_establishments(): void
    {
        Establishment::create(['name' => 'A', 'zone' => 'grand_tunis', 'priority' => 'P1']);
        Establishment::create(['name' => 'B', 'zone' => 'grand_tunis', 'priority' => 'P2']);
        Establishment::create(['name' => 'C', 'zone' => 'sud', 'priority' => 'P1']);

        $response = $this->withHeaders($this->authHeader())->getJson('/api/v1/prospection/dashboard')->assertOk();

        $byZone = collect($response->json('data.by_zone'))->keyBy('zone');
        $byPriority = collect($response->json('data.by_priority'))->keyBy('priority');

        $this->assertSame(2, $byZone['grand_tunis']['count']);
        $this->assertSame(1, $byZone['sud']['count']);
        $this->assertSame(2, $byPriority['P1']['count']);
        $this->assertSame(1, $byPriority['P2']['count']);
    }

    public function test_response_rate_counts_establishments_not_messages(): void
    {
        $withResponse = Establishment::create(['name' => 'Répond']);
        $withoutResponse = Establishment::create(['name' => 'Muet']);

        // Deux messages envoyés au même prospect, une seule réponse : ne doit
        // pas compter comme 2 contactés pour 1 réponse (0,5) mais bien 1
        // contacté répondant (1,0) — la relance sans réponse ne doit pas
        // diluer artificiellement le taux d'un prospect qui a fini par
        // répondre.
        ProspectionAction::create(['establishment_id' => $withResponse->id, 'type' => 'message_envoye', 'occurred_at' => now()]);
        ProspectionAction::create(['establishment_id' => $withResponse->id, 'type' => 'message_envoye', 'occurred_at' => now()]);
        ProspectionAction::create(['establishment_id' => $withResponse->id, 'type' => 'reponse_recue', 'occurred_at' => now()]);

        ProspectionAction::create(['establishment_id' => $withoutResponse->id, 'type' => 'message_envoye', 'occurred_at' => now()]);

        $response = $this->withHeaders($this->authHeader())->getJson('/api/v1/prospection/dashboard')->assertOk();

        $this->assertSame(2, $response->json('data.response_rate.contacted'));
        $this->assertSame(1, $response->json('data.response_rate.responded'));
        $this->assertSame(0.5, $response->json('data.response_rate.rate'));
    }

    public function test_response_rate_is_zero_when_nobody_was_contacted_yet(): void
    {
        $response = $this->withHeaders($this->authHeader())->getJson('/api/v1/prospection/dashboard')->assertOk();

        $this->assertSame(0, $response->json('data.response_rate.contacted'));
        $this->assertSame(0, $response->json('data.response_rate.rate'));
    }

    public function test_top_objections_are_tallied_across_actions_and_sorted_descending(): void
    {
        $establishment = Establishment::create(['name' => 'A']);

        ProspectionAction::create([
            'establishment_id' => $establishment->id, 'type' => 'note', 'occurred_at' => now(),
            'objections' => ['Prix trop élevé', 'Pas le temps'],
        ]);
        ProspectionAction::create([
            'establishment_id' => $establishment->id, 'type' => 'note', 'occurred_at' => now(),
            'objections' => ['Prix trop élevé'],
        ]);
        ProspectionAction::create([
            'establishment_id' => $establishment->id, 'type' => 'note', 'occurred_at' => now(),
        ]);

        $response = $this->withHeaders($this->authHeader())->getJson('/api/v1/prospection/dashboard')->assertOk();

        $top = $response->json('data.top_objections');
        $this->assertSame(['label' => 'Prix trop élevé', 'count' => 2], $top[0]);
        $this->assertSame(['label' => 'Pas le temps', 'count' => 1], $top[1]);
    }
}
