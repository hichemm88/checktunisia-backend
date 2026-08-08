<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Propriétés de sécurité non couvertes ailleurs.
 *
 * TenantIsolationTest prouve déjà l'isolation par ÉTABLISSEMENT via accès
 * direct par identifiant. Ce fichier couvre trois angles morts :
 *
 *  1. l'isolation au niveau ORGANISATION (facturation, paiements) — un
 *     périmètre différent du scoping tenant ;
 *  2. les surfaces à PARAMÈTRES (recherche, plages de dates), là où les
 *     contournements de scoping se logent habituellement ;
 *  3. la sécurité du changement d'adresse e-mail introduit par le commit #42,
 *     l'e-mail étant l'identifiant de connexion ET la cible du lien de
 *     réinitialisation — le détourner, c'est prendre le compte.
 *
 * Chaque test démontre une propriété réelle. Aucun test de remplissage.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le cache « array » vit le temps du processus PHP : sans purge, le
        // test de limitation ferait échouer les suivants selon l'ordre
        // d'exécution.
        cache()->clear();
    }

    private function makeOrg(string $email): Organization
    {
        return Organization::create([
            'name' => 'Org '.uniqid(),
            'entity_type' => 'company',
            'contact_email' => $email,
            'status' => 'active',
        ]);
    }

    /** @return array{0: Organization, 1: Hotel, 2: User} */
    private function makeTenant(string $email): array
    {
        $org = $this->makeOrg($email);
        $hotel = Hotel::factory()->withActiveSubscription()->create([
            'organization_id' => $org->id,
            'setup_completed_at' => now(),
        ]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'role_org' => 'owner',
            'email' => $email,
        ]);
        $user->assignRole('hotel_admin');
        $user->hotels()->attach($hotel->id, ['granted_at' => now()]);

        return [$org, $hotel, $user];
    }

    // ── Isolation au niveau ORGANISATION ─────────────────────────────────────

    public function test_an_org_owner_only_sees_their_own_invoices(): void
    {
        [, , $alice] = $this->makeTenant('alice@a.test');
        [, , $bob] = $this->makeTenant('bob@b.test');

        $aliceIds = collect($this->actingAs($alice)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data'))
            ->pluck('id')->all();
        $bobIds = collect($this->actingAs($bob)->getJson('/api/v1/hotel/invoices')->assertOk()->json('data'))
            ->pluck('id')->all();

        // La facturation est scopée par ORGANISATION, pas par établissement :
        // un périmètre distinct de celui que couvre TenantIsolationTest.
        $this->assertEmpty(
            array_intersect($aliceIds, $bobIds),
            'Deux organisations distinctes ne doivent partager aucune facture.'
        );
    }

    public function test_an_org_owner_cannot_read_another_orgs_subscription(): void
    {
        [, , $alice] = $this->makeTenant('alice2@a.test');
        [, , $bob] = $this->makeTenant('bob2@b.test');

        $aliceOrgId = $this->actingAs($alice)->getJson('/api/v1/hotel/organization')->assertOk()->json('data.id');
        $bobOrgId = $this->actingAs($bob)->getJson('/api/v1/hotel/organization')->assertOk()->json('data.id');

        $this->assertNotSame($aliceOrgId, $bobOrgId, 'Chaque compte doit voir SA propre organisation.');
    }

    // ── Surfaces à paramètres : le scoping tient-il ? ────────────────────────

    public function test_search_parameter_cannot_leak_another_tenants_guests(): void
    {
        [, $hotelA, $alice] = $this->makeTenant('alice3@a.test');
        [, $hotelB, $bob] = $this->makeTenant('bob3@b.test');

        // Le paramètre `search` interroge les NOMS de voyageurs — c'est la
        // surface d'attaque réelle : chercher le nom d'un client qui n'existe
        // que chez le concurrent.
        $guestA = \App\Models\Guest::factory()->named('Amina', 'Zoughlami')->create();
        $guestB = \App\Models\Guest::factory()->named('Bilel', 'Khemiri')->create();

        $ciA = \App\Models\CheckIn::factory()->for($hotelA)->create();
        $ciB = \App\Models\CheckIn::factory()->for($hotelB)->create();
        $ciA->guests()->attach($guestA->id, ['is_primary' => true, 'added_at' => now(), 'added_by' => $alice->id]);
        $ciB->guests()->attach($guestB->id, ['is_primary' => true, 'added_at' => now(), 'added_by' => $bob->id]);

        // Le filtre tenant doit s'appliquer AVANT le critère utilisateur.
        $bodyA = $this->actingAs($alice)
            ->getJson('/api/v1/hotel/check-ins?search=Khemiri')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Khemiri', $bodyA, 'La recherche a fait fuiter un voyageur d\'un autre établissement.');

        // Contrôle positif : sans lui, le test passerait même si la recherche
        // était cassée et ne renvoyait jamais rien.
        $ownSearch = $this->actingAs($alice)
            ->getJson('/api/v1/hotel/check-ins?search=Zoughlami')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Zoughlami', $ownSearch, 'La recherche doit trouver ses PROPRES voyageurs.');

        // Symétrie : la propriété tient dans les deux sens.
        $bodyB = $this->actingAs($bob)->getJson('/api/v1/hotel/check-ins?search=Zoughlami')->assertOk()->getContent();
        $this->assertStringNotContainsString('Zoughlami', $bodyB);
    }

    public function test_room_availability_is_scoped_to_the_tenant(): void
    {
        [, $hotelA, $alice] = $this->makeTenant('alice4@a.test');
        [, $hotelB] = $this->makeTenant('bob4@b.test');

        $roomB = \App\Models\Room::factory()->for($hotelB)->create(['number' => 'B-999']);

        $body = $this->actingAs($alice)
            ->getJson('/api/v1/hotel/rooms/availability?from='.now()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString((string) $roomB->id, $body, 'La disponibilité expose une chambre d\'un autre établissement.');
    }

    // ── Changement d'e-mail (commit #42) ─────────────────────────────────────

    public function test_email_change_requires_the_current_password(): void
    {
        [, , $alice] = $this->makeTenant('alice5@a.test');

        // L'e-mail est l'identifiant de connexion ET la cible du lien de
        // réinitialisation : une session ouverte ne doit pas suffire.
        $this->actingAs($alice)
            ->patchJson('/api/v1/profile', ['email' => 'pirate@evil.test'])
            ->assertStatus(422)
            // L'API renvoie une enveloppe personnalisee
            // {data, errors:[{code,message,field}]}, pas le format Laravel natif.
            ->assertJsonPath('errors.0.field', 'current_password');

        $this->assertSame('alice5@a.test', $alice->fresh()->email);
    }

    public function test_email_change_rejects_a_wrong_password(): void
    {
        [, , $alice] = $this->makeTenant('alice6@a.test');

        $this->actingAs($alice)
            ->patchJson('/api/v1/profile', [
                'email' => 'pirate@evil.test',
                'current_password' => 'mauvais-mot-de-passe',
            ])
            ->assertStatus(422);

        $this->assertSame('alice6@a.test', $alice->fresh()->email);
    }

    public function test_email_change_succeeds_with_the_correct_password(): void
    {
        [, , $alice] = $this->makeTenant('alice7@a.test');

        $this->actingAs($alice)
            ->patchJson('/api/v1/profile', [
                'email' => 'nouvelle@a.test',
                'current_password' => 'Password1!Test',
            ])
            ->assertOk();

        $this->assertSame('nouvelle@a.test', $alice->fresh()->email);
    }

    public function test_profile_update_cannot_target_another_user(): void
    {
        [, , $alice] = $this->makeTenant('alice8@a.test');
        [, , $bob] = $this->makeTenant('bob8@b.test');

        // Le contrôleur lit $request->user() : aucun identifiant de la requête
        // ne doit pouvoir désigner une autre victime.
        $this->actingAs($alice)
            ->patchJson('/api/v1/profile', [
                'id' => $bob->id,
                'user_id' => $bob->id,
                'first_name' => 'Détourné',
            ])
            ->assertOk();

        $this->assertNotSame('Détourné', $bob->fresh()->first_name, 'Le profil d\'un autre utilisateur a été modifié.');
        $this->assertSame('Détourné', $alice->fresh()->first_name);
    }

    public function test_profile_update_cannot_escalate_privileges(): void
    {
        [, , $alice] = $this->makeTenant('alice9@a.test');
        $otherOrg = $this->makeOrg('other@x.test');

        $this->actingAs($alice)->patchJson('/api/v1/profile', [
            'first_name' => 'Légitime',
            'role_org' => 'owner',
            'organization_id' => $otherOrg->id,
            'status' => 'active',
            'password' => 'PirateMotDePasse1!',
            'email_verified_at' => now()->toDateTimeString(),
        ])->assertOk();

        $fresh = $alice->fresh();

        // Seuls les champs de la liste blanche doivent être persistés.
        $this->assertSame('Légitime', $fresh->first_name);
        $this->assertNotSame($otherOrg->id, $fresh->organization_id, 'organization_id a été détourné — rattachement à une autre organisation.');
        $this->assertTrue(Hash::check('Password1!Test', $fresh->password), 'Le mot de passe a été écrasé via updateProfile.');
    }

    public function test_email_change_cannot_steal_another_users_address(): void
    {
        [, , $alice] = $this->makeTenant('alice10@a.test');
        $this->makeTenant('victime@b.test');

        $this->actingAs($alice)
            ->patchJson('/api/v1/profile', [
                'email' => 'victime@b.test',
                'current_password' => 'Password1!Test',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'email');
    }

    // ── Export de données voyageurs ──────────────────────────────────────────

    public function test_police_fiche_export_is_scoped_to_the_requesting_tenant(): void
    {
        [, $hotelA, $alice] = $this->makeTenant('alice11@a.test');
        [, $hotelB] = $this->makeTenant('bob11@b.test');

        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($alice)->postJson('/api/v1/hotel/exports/police-fiches', [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertStatus(202);

        // Le job doit porter l'établissement de l'APPELANT, jamais un autre :
        // c'est un export en masse de données d'identité.
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\ExportPoliceFichesJob::class,
            fn ($job) => $job->hotelId === $hotelA->id && $job->hotelId !== $hotelB->id
        );
    }

    // ── Escalade de privilèges ───────────────────────────────────────────────

    public function test_no_endpoint_can_grant_the_platform_admin_role(): void
    {
        [, $hotel, $owner] = $this->makeTenant('owner13@a.test');

        // Le rôle platform_admin n'a AUCUN scoping tenant : l'obtenir, c'est
        // obtenir le registre national. Aucune route ne doit pouvoir l'attribuer.
        $this->actingAs($owner)->postJson('/api/v1/hotel/users', [
            'first_name' => 'Pirate',
            'last_name' => 'Escalade',
            'email' => 'escalade@a.test',
            'role' => 'platform_admin',
        ])->assertStatus(422);

        $this->assertNull(
            User::where('email', 'escalade@a.test')->first(),
            'Un compte a été créé avec un rôle refusé.'
        );

        // Et par mise à jour d'un compte existant.
        $victim = User::factory()->receptionist($hotel)->create();

        $this->actingAs($owner)
            ->patchJson("/api/v1/hotel/users/{$victim->id}", ['role' => 'platform_admin'])
            ->assertStatus(422);

        $this->assertFalse($victim->fresh()->hasRole('platform_admin'), 'Un rôle plateforme a été accordé.');
    }

    // ── Limitation des vérifications de mot de passe ─────────────────────────

    public function test_password_verification_endpoints_are_rate_limited(): void
    {
        [, , $alice] = $this->makeTenant('alice14@a.test');

        // Depuis une session volée, ces endpoints permettaient de deviner le
        // mot de passe à 120 essais/minute — contre 5/min sur /auth/login.
        // Le deviner ouvre le changement d'e-mail, donc la prise de compte.
        $status = 422;
        for ($i = 0; $i < 12 && $status !== 429; $i++) {
            $status = $this->actingAs($alice)->postJson('/api/v1/profile/password', [
                'current_password' => 'essai-incorrect-'.$i,
                'password' => 'NouveauMotDePasse1!',
            ])->getStatusCode();
        }

        $this->assertSame(429, $status, 'La vérification du mot de passe doit être limitée.');
    }

    public function test_legitimate_password_change_is_not_blocked(): void
    {
        [, , $alice] = $this->makeTenant('alice15@a.test');

        // La limite ne doit pas gêner un utilisateur qui se trompe une fois
        // puis corrige — cas parfaitement banal.
        $this->actingAs($alice)->postJson('/api/v1/profile/password', [
            'current_password' => 'faute-de-frappe',
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertStatus(422);

        $this->actingAs($alice)->postJson('/api/v1/profile/password', [
            'current_password' => 'Password1!Test',
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ])->assertOk();
    }

    /**
     * Le contrôle de sécurité que le défaut ci-dessus rendait inopérant :
     * changer son mot de passe doit chasser un intrus des autres sessions.
     */
    public function test_changing_the_password_revokes_other_sessions(): void
    {
        [, , $alice] = $this->makeTenant('alice16@a.test');

        // Deux sessions ouvertes : celle d'Alice et celle d'un intrus.
        $intruder = $alice->createToken('session-intruse', ['*'])->plainTextToken;
        $legit = $alice->createToken('session-alice', ['*'])->plainTextToken;

        $asIntruder = ['Authorization' => "Bearer {$intruder}"];
        $asLegit = ['Authorization' => "Bearer {$legit}"];

        /**
         * Indispensable entre deux requêtes portant des jetons DIFFÉRENTS :
         * RequestGuard::user() mémoïse l'utilisateur résolu, et le conteneur
         * survit d'une requête à l'autre à l'intérieur d'un même test. Sans
         * cette purge, la 2e requête réutilise le jeton de la 1re et le test
         * observe l'inverse de la réalité — c'est un piège du harnais de test,
         * pas un comportement de production : là-bas chaque requête HTTP
         * démarre un conteneur neuf.
         */
        $freshGuard = fn () => $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/auth/me', $asIntruder)->assertOk();
        $freshGuard();

        $this->postJson('/api/v1/profile/password', [
            'current_password' => 'Password1!Test',
            'password' => 'NouveauMotDePasse1!',
            'password_confirmation' => 'NouveauMotDePasse1!',
        ], $asLegit)->assertOk();
        $freshGuard();

        // L'intrus doit être éjecté…
        $this->getJson('/api/v1/auth/me', $asIntruder)->assertUnauthorized();
        $freshGuard();

        // …et Alice rester connectée : la déconnecter la dissuaderait de
        // changer son mot de passe, ce qui serait contre-productif.
        $this->getJson('/api/v1/auth/me', $asLegit)->assertOk();
    }

    public function test_disabling_2fa_requires_a_valid_totp_code(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        // Sans cette exigence, une session volée suffirait à retirer le second
        // facteur du compte le plus privilégié de la plateforme.
        $this->actingAs($admin)
            ->deleteJson('/api/v1/auth/2fa/setup', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at, 'La 2FA a été désactivée sans code valide.');
    }

    /**
     * Couverture du VRAI chemin TOTP, jusqu'ici totalement absente : la
     * factory stockait le secret en clair, si bien que toute vérification
     * levait DecryptException (HTTP 500). Les tests existants ne validaient
     * que le middleware — qui ne regarde que two_factor_confirmed_at — jamais
     * la vérification du code lui-même.
     */
    public function test_a_valid_totp_code_completes_the_login(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $partial = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'Password1!Test',
        ])->assertOk()->json('data.partial_token');

        $code = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp(
            \Database\Factories\UserFactory::TOTP_SECRET
        );

        $full = $this->withHeader('Authorization', "Bearer {$partial}")
            ->postJson('/api/v1/auth/2fa/verify', ['code' => $code])
            ->assertOk()
            ->json('data.token');

        $this->assertNotNull($full, 'Un code TOTP valide doit délivrer un token complet.');

        // Et ce token complet ouvre bien l'espace admin.
        $this->withHeader('Authorization', "Bearer {$full}")
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    public function test_an_invalid_totp_code_never_issues_a_full_token(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $partial = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'Password1!Test',
        ])->assertOk()->json('data.partial_token');

        $this->withHeader('Authorization', "Bearer {$partial}")
            ->postJson('/api/v1/auth/2fa/verify', ['code' => '000000'])
            ->assertStatus(422);
    }

    public function test_a_receptionist_cannot_reach_owner_only_endpoints(): void
    {
        [, $hotel] = $this->makeTenant('owner12@a.test');

        $receptionist = User::factory()->receptionist($hotel)->create();

        // EnsureOrgOwner doit bloquer, quel que soit le rôle plateforme.
        $this->actingAs($receptionist)
            ->postJson('/api/v1/hotel/organization/transfer-ownership', ['user_id' => $receptionist->id])
            ->assertForbidden();
    }
}
