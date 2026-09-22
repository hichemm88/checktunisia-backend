<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Prospection\FakeWebPushSender;
use Tests\TestCase;

/**
 * Déclencheur 3/3 (§ Notifications push) — voir ProspectionActionObserver.
 */
class ColleagueActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(ProspectionUser $user): array
    {
        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $user->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_a_response_received_notifies_colleagues_but_never_the_author(): void
    {
        $author = ProspectionUser::create([
            'name' => 'Auteur', 'email' => 'auteur@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        $colleague = ProspectionUser::create([
            'name' => 'Collègue', 'email' => 'collegue@qayed.tn', 'password' => Hash::make('x'),
            'notif_activity_enabled' => true,
        ]);
        $establishment = Establishment::create(['name' => 'Dar Zaghouan']);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->withHeaders($this->authHeader($author))
            ->postJson("/api/v1/prospection/establishments/{$establishment->id}/actions", [
                'type' => 'reponse_recue',
                'content' => 'Intéressée',
            ])
            ->assertStatus(201);

        $this->assertSame([$colleague->id], $fake->recipientIds());
    }

    public function test_a_plain_note_or_call_notifies_nobody(): void
    {
        $author = ProspectionUser::create([
            'name' => 'Auteur', 'email' => 'auteur@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        ProspectionUser::create([
            'name' => 'Collègue', 'email' => 'collegue@qayed.tn', 'password' => Hash::make('x'),
            'notif_activity_enabled' => true,
        ]);
        $establishment = Establishment::create(['name' => 'Dar Zaghouan']);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->withHeaders($this->authHeader($author))
            ->postJson("/api/v1/prospection/establishments/{$establishment->id}/actions", ['type' => 'note', 'content' => 'RAS'])
            ->assertStatus(201);
        $this->withHeaders($this->authHeader($author))
            ->postJson("/api/v1/prospection/establishments/{$establishment->id}/actions", ['type' => 'appel'])
            ->assertStatus(201);

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_a_colleague_who_disabled_activity_notifications_is_skipped(): void
    {
        $author = ProspectionUser::create([
            'name' => 'Auteur', 'email' => 'auteur@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        ProspectionUser::create([
            'name' => 'Collègue', 'email' => 'collegue@qayed.tn', 'password' => Hash::make('x'),
            'notif_activity_enabled' => false,
        ]);
        $establishment = Establishment::create(['name' => 'Dar Zaghouan']);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->withHeaders($this->authHeader($author))
            ->postJson("/api/v1/prospection/establishments/{$establishment->id}/actions", ['type' => 'reponse_recue'])
            ->assertStatus(201);

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_moving_the_pipeline_backwards_does_not_notify(): void
    {
        $author = ProspectionUser::create([
            'name' => 'Auteur', 'email' => 'auteur@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        ProspectionUser::create([
            'name' => 'Collègue', 'email' => 'collegue@qayed.tn', 'password' => Hash::make('x'),
            'notif_activity_enabled' => true,
        ]);
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'demo_faite']);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->withHeaders($this->authHeader($author))
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'contacte'])
            ->assertOk();

        $this->assertSame([], $fake->recipientIds());
    }

    public function test_moving_to_client_notifies_colleagues(): void
    {
        $author = ProspectionUser::create([
            'name' => 'Auteur', 'email' => 'auteur@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        $colleague = ProspectionUser::create([
            'name' => 'Collègue', 'email' => 'collegue@qayed.tn', 'password' => Hash::make('x'),
            'notif_activity_enabled' => true,
        ]);
        $establishment = Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'essai_en_cours']);

        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $this->withHeaders($this->authHeader($author))
            ->patchJson("/api/v1/prospection/establishments/{$establishment->id}", ['status' => 'client'])
            ->assertOk();

        $this->assertSame([$colleague->id], $fake->recipientIds());
        $this->assertStringContainsString('client', $fake->sent[0]['body']);
    }
}
