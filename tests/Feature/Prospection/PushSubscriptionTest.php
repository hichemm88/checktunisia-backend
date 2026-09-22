<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\ProspectionUser;
use App\Models\Prospection\PushSubscription;
use App\Services\Prospection\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Prospection\FakeWebPushSender;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private ProspectionUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);
    }

    private function authHeader(): array
    {
        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $this->user->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_a_device_can_subscribe(): void
    {
        $this->withHeaders($this->authHeader())
            ->postJson('/api/v1/prospection/push-subscriptions', [
                'endpoint' => 'https://push.example/abc',
                'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
            ])
            ->assertStatus(201);

        $this->assertSame(1, PushSubscription::count());
    }

    public function test_subscribing_the_same_endpoint_twice_upserts_instead_of_duplicating(): void
    {
        $headers = $this->authHeader();
        $payload = [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
        ];

        $this->withHeaders($headers)->postJson('/api/v1/prospection/push-subscriptions', $payload)->assertStatus(201);
        $this->withHeaders($headers)->postJson('/api/v1/prospection/push-subscriptions', $payload)->assertStatus(201);

        $this->assertSame(1, PushSubscription::count());
    }

    public function test_a_device_can_unsubscribe(): void
    {
        $headers = $this->authHeader();
        $this->withHeaders($headers)->postJson('/api/v1/prospection/push-subscriptions', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
        ])->assertStatus(201);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/prospection/push-subscriptions', ['endpoint' => 'https://push.example/abc'])
            ->assertOk();

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_test_endpoint_sends_a_push_to_the_caller_only(): void
    {
        $fake = new FakeWebPushSender;
        $this->app->instance(WebPushSender::class, $fake);

        $headers = $this->authHeader();

        $this->withHeaders($headers)->postJson('/api/v1/prospection/push-subscriptions', [
            'endpoint' => 'https://push.example/abc',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-secret'],
        ])->assertStatus(201);

        $this->withHeaders($headers)->postJson('/api/v1/prospection/push-subscriptions/test')->assertOk();

        $this->assertSame([$this->user->id], $fake->recipientIds());
    }

    public function test_test_endpoint_fails_gracefully_without_any_subscription(): void
    {
        $this->withHeaders($this->authHeader())
            ->postJson('/api/v1/prospection/push-subscriptions/test')
            ->assertStatus(422);
    }

    public function test_unsubscribing_never_touches_another_users_subscription(): void
    {
        // Abonnement de l'AUTRE utilisateur créé directement en base plutôt
        // que via une connexion simulée : Auth::viaRequest() (RequestGuard)
        // mémorise le premier utilisateur résolu pour toute la durée du
        // test — un second postJson() authentifié comme quelqu'un d'autre,
        // dans le MÊME test, continuerait de résoudre le premier (voir
        // ProspectionAuthTest, même piège déjà documenté pour la création
        // de comptes).
        $other = ProspectionUser::create([
            'name' => 'Autre', 'email' => 'autre@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);
        PushSubscription::create([
            'user_id' => $other->id,
            'endpoint' => 'https://push.example/theirs',
            'endpoint_hash' => hash('sha256', 'https://push.example/theirs'),
            'public_key' => 'public-key',
            'auth_token' => 'auth-secret',
        ]);

        $this->withHeaders($this->authHeader())
            ->deleteJson('/api/v1/prospection/push-subscriptions', ['endpoint' => 'https://push.example/theirs'])
            ->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($other->id, PushSubscription::first()->user_id);
    }
}
