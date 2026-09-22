<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\ProspectionAccessToken;
use App\Models\Prospection\ProspectionUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProspectionAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_a_bearer_token(): void
    {
        ProspectionUser::create([
            'name' => 'Hichem',
            'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'),
            'role' => 'admin',
        ]);

        $response = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => 'hichem@qayed.tn',
            'password' => 'un-mot-de-passe-solide',
        ])->assertOk();

        $response->assertJsonPath('data.user.email', 'hichem@qayed.tn');
        $response->assertJsonPath('data.user.role', 'admin');
        $this->assertNotEmpty($response->json('data.token'));

        // Le hash, jamais le jeton en clair, est ce qui est stocké.
        $this->assertDatabaseMissing('access_tokens', [
            'token_hash' => $response->json('data.token'),
        ], 'prospection');
        $this->assertSame(1, ProspectionAccessToken::count());
    }

    /**
     * Régression : un User-Agent de navigateur réel (110-150+ caractères
     * pour Chrome desktop) dépassait la colonne device_label(100), Postgres
     * rejetait l'insertion et /auth/login répondait 500 en production —
     * jamais détecté avant parce que le client de test HTTP de Laravel
     * n'envoie par défaut aucun User-Agent réaliste.
     */
    public function test_login_with_a_realistic_long_user_agent_does_not_500(): void
    {
        ProspectionUser::create([
            'name' => 'Hichem',
            'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'),
            'role' => 'admin',
        ]);

        $chromeUserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
        $this->assertGreaterThan(100, strlen($chromeUserAgent));

        $this->withHeader('User-Agent', $chromeUserAgent)
            ->postJson('/api/v1/prospection/auth/login', [
                'email' => 'hichem@qayed.tn',
                'password' => 'un-mot-de-passe-solide',
            ])
            ->assertOk();

        $this->assertSame(
            substr($chromeUserAgent, 0, 100),
            ProspectionAccessToken::first()->device_label,
        );
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        ProspectionUser::create([
            'name' => 'Hichem',
            'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'),
            'role' => 'admin',
        ]);

        $this->postJson('/api/v1/prospection/auth/login', [
            'email' => 'hichem@qayed.tn',
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(422);

        $this->assertSame(0, ProspectionAccessToken::count());
    }

    public function test_token_grants_access_to_protected_routes(): void
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem',
            'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'),
            'role' => 'admin',
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => 'hichem@qayed.tn',
            'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/prospection/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/prospection/auth/me')->assertStatus(401);
    }

    /**
     * Un jeton de PRODUCTION (Sanctum, App\Models\User) ne doit jamais
     * ouvrir le CRM de prospection — les deux guards, et les deux tables de
     * jetons, sont totalement indépendants (voir config/auth.php).
     */
    public function test_production_sanctum_token_does_not_grant_prospection_access(): void
    {
        $productionUser = User::factory()->create();
        $sanctumToken = $productionUser->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$sanctumToken}")
            ->getJson('/api/v1/prospection/auth/me')
            ->assertStatus(401);
    }

    public function test_admin_can_create_a_member_account(): void
    {
        $admin = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'admin@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->issueToken($admin))
            ->postJson('/api/v1/prospection/users', [
                'name' => 'Nouveau membre',
                'email' => 'nouveau@qayed.tn',
                'password' => 'un-mot-de-passe-solide',
                'role' => 'membre',
            ])->assertStatus(201);
    }

    public function test_a_member_cannot_create_an_account(): void
    {
        $member = ProspectionUser::create([
            'name' => 'Coéquipier', 'email' => 'membre@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'membre',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->issueToken($member))
            ->postJson('/api/v1/prospection/users', [
                'name' => 'Refusé',
                'email' => 'refuse@qayed.tn',
                'password' => 'un-mot-de-passe-solide',
                'role' => 'membre',
            ])->assertStatus(403);
    }

    private function issueToken(ProspectionUser $user): string
    {
        return $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $user->email,
            'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');
    }
}
