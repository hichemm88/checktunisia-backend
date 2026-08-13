<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\VirtualAuthenticator;
use Tests\TestCase;

/**
 * Authentification par passkey (WebAuthn), de bout en bout.
 *
 * Les cérémonies passent par un authentificateur simulé qui détient une vraie
 * paire de clés ES256 (Tests\Support\VirtualAuthenticator) : les signatures
 * sont réelles, et les cas d'attaque ci-dessous (rejeu, mauvais origin, mauvais
 * RP ID, signature forgée, compteur qui recule) échouent pour la bonne raison,
 * pas parce qu'un raccourci de test les rendrait impossibles.
 */
class PasskeyAuthTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:5173';
    private const RP_ID  = 'localhost';

    protected function setUp(): void
    {
        parent::setUp();

        // Ce que verrait le serveur en production : RP ID = domaine du
        // frontend, origines autorisées explicitement listées.
        config([
            'webauthn.rp_id'             => self::RP_ID,
            'webauthn.origins'           => [self::ORIGIN],
            'webauthn.user_verification' => 'required',
        ]);
    }

    // ── Enregistrement ───────────────────────────────────────────────────────

    public function test_a_user_registers_a_passkey_and_receives_recovery_codes(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();

        $response = $this->registerPasskey($token, $auth, 'iPhone de Hichem')->assertCreated();

        $response->assertJsonPath('data.passkey.device_name', 'iPhone de Hichem');
        $this->assertCount(10, $response->json('data.recovery_codes'), 'La première passkey remet des codes de récupération.');
        $this->assertSame(1, $response->json('data.security.passkeys_count'));

        $credential = WebauthnCredential::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['internal', 'hybrid'], $credential->transports);
        $this->assertTrue($credential->uv_initialized, 'Le bit de vérification utilisateur doit être conservé.');

        // Le serveur ne stocke QUE du matériel public. Aucune colonne ne peut
        // contenir de donnée biométrique : elle ne quitte jamais l'appareil.
        $stored = $credential->getAttributes();
        $this->assertArrayNotHasKey('private_key', $stored);
        $this->assertNotEmpty($stored['public_key']);
    }

    public function test_a_second_passkey_does_not_reissue_recovery_codes(): void
    {
        [, $token] = $this->hotelUser();

        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();
        $second = $this->registerPasskey($token, new VirtualAuthenticator(), 'MacBook')->assertCreated();

        $this->assertNull($second->json('data.recovery_codes'), 'Les codes existants ne doivent pas être invalidés en silence.');
        $this->assertSame(2, $second->json('data.security.passkeys_count'));
    }

    public function test_a_registration_challenge_issued_to_another_user_is_refused(): void
    {
        [, $tokenA] = $this->hotelUser();
        [, $tokenB] = $this->hotelUser();

        // Le challenge est émis pour A…
        $options = $this->api($tokenA)->postJson('/api/v1/auth/passkeys/options')->json('data');
        $credential = (new VirtualAuthenticator())->register($options['public_key'], self::ORIGIN);

        // …et présenté par B.
        $this->api($tokenB)->postJson('/api/v1/auth/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => $credential,
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'PASSKEY_CHALLENGE_INVALID');
    }

    public function test_registration_stops_at_the_configured_limit(): void
    {
        config(['webauthn.max_credentials_per_user' => 2]);
        [, $token] = $this->hotelUser();

        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();
        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();

        $this->api($token)->postJson('/api/v1/auth/passkeys/options')
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PASSKEY_LIMIT_REACHED');
    }

    // ── Connexion ────────────────────────────────────────────────────────────

    public function test_a_passkey_opens_a_full_session_without_asking_for_a_totp(): void
    {
        // Compte autorité : la TOTP est configurée ET obligatoire sur ses
        // routes. La passkey doit malgré tout suffire — c'est la règle demandée.
        $org  = AuthorityOrganization::factory()->police('Tunis')->create();
        $user = User::factory()->authorityUser($org)->create();
        $token = $this->fullToken($user);

        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $login = $this->loginWithPasskey($auth, $user)->assertOk();

        $this->assertNotNull($login->json('data.token'));
        $this->assertNull($login->json('data.requires_2fa'), 'Aucune étape TOTP ne doit être demandée.');
        $this->assertSame('passkey', $login->json('data.user.security.auth_method'));

        // La session ouverte par passkey est une session complète : elle passe
        // le middleware require.2fa et atteint les routes du portail autorité.
        $sessionToken = $login->json('data.token');
        $this->api($sessionToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->api($sessionToken)->getJson('/api/v1/authority/dashboard')->assertOk();
    }

    public function test_a_passkey_session_reaches_the_admin_area_without_a_totp_step(): void
    {
        $user  = User::factory()->platformAdminWithout2FA()->create();
        $token = $this->fullToken($user);

        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $sessionToken = $this->loginWithPasskey($auth, $user)->assertOk()->json('data.token');

        $this->api($sessionToken)->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_last_used_at_and_sign_counter_are_updated_on_login(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $this->loginWithPasskey($auth, $user)->assertOk();

        $credential = WebauthnCredential::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, $credential->sign_count);
        $this->assertNotNull($credential->last_used_at);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_credential_works_for_every_role(): void
    {
        $hotel = Hotel::factory()->create();
        $org   = AuthorityOrganization::factory()->police('Sfax')->create();

        $accounts = [
            'hotel_admin'    => User::factory()->hotelAdmin($hotel)->create(),
            'receptionist'   => User::factory()->receptionist($hotel)->create(),
            'platform_admin' => User::factory()->platformAdmin()->create(),
            'authority_user' => User::factory()->authorityUser($org)->create(),
        ];

        foreach ($accounts as $role => $user) {
            $auth = new VirtualAuthenticator();
            $this->registerPasskey($this->fullToken($user), $auth)->assertCreated();

            $this->loginWithPasskey($auth, $user)
                ->assertOk()
                ->assertJsonPath('data.user.role', $role);
        }
    }

    public function test_a_role_change_is_reflected_on_the_next_passkey_login(): void
    {
        $hotel = Hotel::factory()->create();
        $user  = User::factory()->receptionist($hotel)->create();

        $auth = new VirtualAuthenticator();
        $this->registerPasskey($this->fullToken($user), $auth)->assertCreated();

        $this->loginWithPasskey($auth, $user)->assertJsonPath('data.user.role', 'receptionist');

        // La passkey est liée au COMPTE, pas au rôle : elle survit au changement.
        $user->syncRoles(['hotel_admin']);

        $this->loginWithPasskey($auth, $user)
            ->assertOk()
            ->assertJsonPath('data.user.role', 'hotel_admin');
    }

    // ── Rejets ───────────────────────────────────────────────────────────────

    public function test_a_response_cannot_be_replayed(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $options    = $this->api()->postJson('/api/v1/auth/passkey/options')->json('data');
        $assertion  = $auth->assert($options['public_key'], self::ORIGIN, $user->fresh()->webauthn_user_handle);
        $payload    = ['challenge_id' => $options['challenge_id'], 'credential' => $assertion];

        $this->api()->postJson('/api/v1/auth/passkey/verify', $payload)->assertOk();

        // Exactement la même réponse, renvoyée une seconde fois.
        $this->api()->postJson('/api/v1/auth/passkey/verify', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'PASSKEY_CHALLENGE_INVALID');
    }

    public function test_an_expired_challenge_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $options   = $this->api()->postJson('/api/v1/auth/passkey/options')->json('data');
        $assertion = $auth->assert($options['public_key'], self::ORIGIN, $user->fresh()->webauthn_user_handle);

        $this->travel(config('webauthn.challenge_ttl') + 60)->seconds();

        $this->api()->postJson('/api/v1/auth/passkey/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => $assertion,
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'PASSKEY_CHALLENGE_INVALID');
    }

    public function test_a_forged_signature_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $this->loginWithPasskey($auth, $user, forgeSignature: true)
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_a_response_from_another_origin_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        // Le scénario de hameçonnage : la page qui appelle WebAuthn n'est pas
        // la nôtre. L'appareil signe, mais l'origin trahit le site pirate.
        $this->loginWithPasskey($auth, $user, origin: 'https://qayed-connexion.example')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_a_response_for_another_rp_id_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $this->loginWithPasskey($auth, $user, rpIdOverride: 'attaquant.example')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_a_registration_from_another_origin_is_refused(): void
    {
        [, $token] = $this->hotelUser();

        $options    = $this->api($token)->postJson('/api/v1/auth/passkeys/options')->json('data');
        $credential = (new VirtualAuthenticator())->register($options['public_key'], 'https://attaquant.example');

        $this->api($token)->postJson('/api/v1/auth/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => $credential,
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'PASSKEY_INVALID');

        $this->assertSame(0, WebauthnCredential::count());
    }

    public function test_a_response_without_user_verification_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        // Présence de l'utilisateur seule (touche effleurée), sans biométrie ni
        // code : insuffisant quand la passkey tient lieu de facteur unique.
        $this->loginWithPasskey($auth, $user, flags: VirtualAuthenticator::FLAG_UP)
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_a_stale_sign_counter_is_refused(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        // Deux connexions légitimes portent le compteur à 2…
        $this->loginWithPasskey($auth, $user)->assertOk();
        $this->loginWithPasskey($auth, $user)->assertOk();

        // …puis un authentificateur cloné rejoue un compteur inférieur.
        $this->loginWithPasskey($auth, $user, signCountOverride: 1)
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_an_unknown_credential_is_refused_without_revealing_anything(): void
    {
        $orphan = new VirtualAuthenticator();
        $user   = User::factory()->create();

        $this->loginWithPasskey($orphan, $user)
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'PASSKEY_VERIFICATION_FAILED');
    }

    public function test_a_suspended_account_cannot_open_a_session_with_its_passkey(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $user->update(['status' => 'suspended']);

        $this->loginWithPasskey($auth, $user)
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'AUTH_ACCOUNT_SUSPENDED');
    }

    public function test_a_session_opened_by_passkey_expires_like_any_other(): void
    {
        [$user, $token] = $this->hotelUser();
        $auth = new VirtualAuthenticator();
        $this->registerPasskey($token, $auth)->assertCreated();

        $sessionToken = $this->loginWithPasskey($auth, $user)->json('data.token');
        $this->api($sessionToken)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(9)->hours();

        $this->api($sessionToken)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── Gestion des passkeys ─────────────────────────────────────────────────

    public function test_a_user_lists_renames_and_revokes_passkeys(): void
    {
        [$user, $token] = $this->hotelUser();

        $iphone  = new VirtualAuthenticator();
        $macbook = new VirtualAuthenticator();
        $this->registerPasskey($token, $iphone, 'iPhone')->assertCreated();
        $macbookId = $this->registerPasskey($token, $macbook, 'MacBook')->json('data.passkey.id');

        $this->api($token)->getJson('/api/v1/auth/passkeys')
            ->assertOk()
            ->assertJsonCount(2, 'data.passkeys');

        $this->api($token)->patchJson("/api/v1/auth/passkeys/{$macbookId}", ['name' => 'MacBook Pro'])
            ->assertOk()
            ->assertJsonPath('data.device_name', 'MacBook Pro');

        $this->api($token)->deleteJson("/api/v1/auth/passkeys/{$macbookId}")
            ->assertOk()
            ->assertJsonPath('data.security.passkeys_count', 1);

        // Révoquée = plus aucune assertion acceptée, même signée correctement.
        $this->loginWithPasskey($macbook, $user)->assertStatus(401);

        // L'autre passkey du compte continue de fonctionner.
        $this->loginWithPasskey($iphone, $user)->assertOk();
    }

    public function test_a_passkey_of_another_account_cannot_be_revoked(): void
    {
        [, $tokenA] = $this->hotelUser();
        [, $tokenB] = $this->hotelUser();

        $passkeyId = $this->registerPasskey($tokenA, new VirtualAuthenticator())->json('data.passkey.id');

        $this->api($tokenB)->deleteJson("/api/v1/auth/passkeys/{$passkeyId}")
            ->assertStatus(404)
            ->assertJsonPath('errors.0.code', 'PASSKEY_NOT_FOUND');

        $this->assertDatabaseHas('webauthn_credentials', ['id' => $passkeyId]);
    }

    public function test_deleting_a_user_removes_their_credentials(): void
    {
        [$user, $token] = $this->hotelUser();
        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();

        $user->forceDelete();

        $this->assertSame(0, WebauthnCredential::where('user_id', $user->id)->count());
    }

    // ── Replis : mot de passe, TOTP, codes de récupération ───────────────────

    public function test_a_user_without_a_passkey_still_logs_in_with_password_and_totp(): void
    {
        $org  = AuthorityOrganization::factory()->police('Bizerte')->create();
        $user = User::factory()->authorityUser($org)->create();

        $login = $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->assertOk();

        $this->assertTrue($login->json('data.requires_2fa'), 'Sans passkey, la 2FA reste exigée.');

        $this->api($login->json('data.partial_token'))
            ->postJson('/api/v1/auth/2fa/verify', ['code' => $this->totpCode()])
            ->assertOk()
            ->assertJsonPath('data.user.security.auth_method', 'totp');
    }

    public function test_password_login_still_works_for_an_account_that_has_a_passkey(): void
    {
        // « Utiliser une autre méthode de connexion » : la passkey n'enferme
        // personne, le mot de passe reste un chemin valide.
        [$user, $token] = $this->hotelUser();
        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();

        $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->assertOk()->assertJsonPath('data.user.security.passkeys_count', 1);
    }

    public function test_a_recovery_code_replaces_the_totp_step_and_serves_only_once(): void
    {
        $org  = AuthorityOrganization::factory()->police('Gabès')->create();
        $user = User::factory()->authorityUser($org)->create();

        $codes = $this->registerPasskey($this->fullToken($user), new VirtualAuthenticator())
            ->json('data.recovery_codes');

        // Appareil perdu : l'utilisateur repasse par le mot de passe, puis
        // présente un code de récupération à la place du TOTP.
        $partial = $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.partial_token');

        $this->api($partial)
            ->postJson('/api/v1/auth/2fa/recovery', ['code' => $codes[0]])
            ->assertOk()
            ->assertJsonPath('data.user.security.auth_method', 'recovery_code')
            ->assertJsonPath('data.user.security.recovery_codes_remaining', 9);

        // Le même code, une seconde fois, ne vaut plus rien.
        $partial2 = $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.partial_token');

        $this->api($partial2)
            ->postJson('/api/v1/auth/2fa/recovery', ['code' => $codes[0]])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'RECOVERY_CODE_INVALID');
    }

    public function test_recovery_codes_are_regenerated_only_with_the_current_password(): void
    {
        [, $token] = $this->hotelUser();
        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();

        $this->api($token)->postJson('/api/v1/auth/recovery-codes', [
            'current_password' => 'mauvais-mot-de-passe',
        ])->assertStatus(422);

        $fresh = $this->api($token)->postJson('/api/v1/auth/recovery-codes', [
            'current_password' => 'Password1!Test',
        ])->assertOk()->json('data.recovery_codes');

        $this->assertCount(10, $fresh);

        // Les codes ne sont jamais stockés en clair.
        $this->assertDatabaseMissing('user_recovery_codes', ['code_hash' => $fresh[0]]);
    }

    // ── Cérémonie : garde-fous d'API ─────────────────────────────────────────

    public function test_the_login_ceremony_never_lists_credentials(): void
    {
        [, $token] = $this->hotelUser();
        $this->registerPasskey($token, new VirtualAuthenticator())->assertCreated();

        $options = $this->api()->postJson('/api/v1/auth/passkey/options')->assertOk()->json('data.public_key');

        // allowCredentials vide : l'endpoint public ne dit rien des comptes
        // existants, et les passkeys découvrables suffisent à la connexion.
        $this->assertSame([], $options['allowCredentials'] ?? []);
        $this->assertSame(self::RP_ID, $options['rpId']);
        $this->assertSame('required', $options['userVerification']);
    }

    public function test_challenges_are_marked_consumed(): void
    {
        $options = $this->api()->postJson('/api/v1/auth/passkey/options')->json('data');

        $challenge = WebauthnChallenge::findOrFail($options['challenge_id']);
        $this->assertNull($challenge->consumed_at);
        $this->assertSame(WebauthnChallenge::CEREMONY_AUTHENTICATION, $challenge->ceremony);

        $this->api()->postJson('/api/v1/auth/passkey/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => ['bidon' => true],
        ])->assertStatus(422);

        $this->assertNotNull($challenge->fresh()->consumed_at, 'Un challenge présenté est consommé, même sur échec.');
    }

    public function test_passkey_endpoints_require_a_full_session(): void
    {
        $org  = AuthorityOrganization::factory()->police('Nabeul')->create();
        $user = User::factory()->authorityUser($org)->create();

        $partial = $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.partial_token');

        $this->api($partial)->getJson('/api/v1/auth/passkeys')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', '2FA_PENDING');

        $this->api($partial)->postJson('/api/v1/auth/passkeys/options')
            ->assertStatus(403);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Prépare une requête, authentifiée par $token ou anonyme.
     *
     * `forgetGuards()` est indispensable : dans un test, l'application est
     * construite UNE fois pour plusieurs requêtes, et le garde Sanctum
     * mémorise le premier utilisateur résolu. Sans cette remise à zéro, une
     * requête censée être faite par B repartirait avec la session de A — ce
     * qui ne se produit jamais en production, où chaque requête a son propre
     * cycle applicatif, mais rendrait ces tests trompeurs.
     */
    private function api(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();

        if ($token === null) {
            unset($this->defaultHeaders['Authorization']);

            return $this;
        }

        return $this->withToken($token);
    }

    /** @return array{0: User, 1: string} */
    private function hotelUser(): array
    {
        $hotel = Hotel::factory()->create();
        $user  = User::factory()->hotelAdmin($hotel)->create();

        return [$user, $this->fullToken($user)];
    }

    /** Token de session complet, en passant par la TOTP si le compte l'exige. */
    private function fullToken(User $user): string
    {
        $login = $this->api()->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->assertOk()->json('data');

        if (($login['requires_2fa'] ?? false) === true) {
            return $this->api($login['partial_token'])
                ->postJson('/api/v1/auth/2fa/verify', ['code' => $this->totpCode()])
                ->assertOk()
                ->json('data.token');
        }

        return $login['token'];
    }

    private function totpCode(): string
    {
        return (new Google2FA())->getCurrentOtp(UserFactory::TOTP_SECRET);
    }

    private function registerPasskey(string $token, VirtualAuthenticator $auth, ?string $name = null): TestResponse
    {
        $options = $this->api($token)
            ->postJson('/api/v1/auth/passkeys/options')
            ->assertOk()
            ->json('data');

        return $this->api($token)->postJson('/api/v1/auth/passkeys', [
            'challenge_id' => $options['challenge_id'],
            'name'         => $name,
            'credential'   => $auth->register($options['public_key'], self::ORIGIN),
        ]);
    }

    private function loginWithPasskey(
        VirtualAuthenticator $auth,
        User $user,
        string $origin = self::ORIGIN,
        ?string $rpIdOverride = null,
        int $flags = VirtualAuthenticator::FLAG_UP | VirtualAuthenticator::FLAG_UV | VirtualAuthenticator::FLAG_BE | VirtualAuthenticator::FLAG_BS,
        ?int $signCountOverride = null,
        bool $forgeSignature = false,
    ): TestResponse {
        $options = $this->api()->postJson('/api/v1/auth/passkey/options')->assertOk()->json('data');

        $assertion = $auth->assert(
            $options['public_key'],
            $origin,
            $user->fresh()->webauthn_user_handle ?? $user->webauthnUserHandle(),
            rpIdOverride: $rpIdOverride,
            flags: $flags,
            signCountOverride: $signCountOverride,
            forgeSignature: $forgeSignature,
        );

        return $this->api()->postJson('/api/v1/auth/passkey/verify', [
            'challenge_id' => $options['challenge_id'],
            'credential'   => $assertion,
        ]);
    }
}
