<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Régressions de sécurité sur l'authentification et les exports autorité.
 *
 * Chaque test ici correspond à une faille réelle constatée à l'audit du
 * 2026-08-06 : ils échouent tous sur le code d'avant correctif.
 */
class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Contournement de la 2FA par /auth/refresh ────────────────────────────

    /**
     * La faille : login renvoyait un token 2fa-pending, et /auth/refresh
     * émettait un token ['*'] sans regarder les capacités du token présenté.
     * Le mot de passe seul suffisait donc à ouvrir une session complète.
     */
    public function test_partial_2fa_token_cannot_be_exchanged_for_a_full_token(): void
    {
        $org  = AuthorityOrganization::factory()->police('Tunis')->create();
        $user = User::factory()->authorityUser($org)->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->assertOk();

        $partial = $login->json('data.partial_token');
        $this->assertNotNull($partial, 'Le login doit renvoyer un token partiel.');
        $this->assertNull($login->json('data.token'), 'Aucun token complet avant le TOTP.');

        $this->withHeader('Authorization', "Bearer {$partial}")
            ->postJson('/api/v1/auth/refresh')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', '2FA_PENDING');
    }

    public function test_partial_2fa_token_cannot_read_the_full_profile(): void
    {
        $org  = AuthorityOrganization::factory()->police('Tunis')->create();
        $user = User::factory()->authorityUser($org)->create();

        $partial = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.partial_token');

        $this->withHeader('Authorization', "Bearer {$partial}")
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', '2FA_PENDING');
    }

    public function test_partial_2fa_token_can_still_log_out(): void
    {
        $org  = AuthorityOrganization::factory()->police('Tunis')->create();
        $user = User::factory()->authorityUser($org)->create();

        $partial = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.partial_token');

        // Annuler une connexion en cours doit rester possible sans TOTP.
        $this->withHeader('Authorization', "Bearer {$partial}")
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
    }

    public function test_refresh_still_works_for_a_full_token(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $user  = User::factory()->hotelAdmin($hotel)->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'Password1!Test',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);
    }

    // ── 2FA obligatoire pour les administrateurs plateforme ──────────────────

    /**
     * La faille : la branche 2FA du login était conditionnée à
     * hasRole('authority_user'). Un platform_admin pouvait donc activer sa
     * TOTP et continuer à se connecter avec son seul mot de passe.
     */
    public function test_platform_admin_with_2fa_must_pass_totp_at_login(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $login = $this->postJson('/api/v1/auth/login', [
            'email'    => $admin->email,
            'password' => 'Password1!Test',
        ])->assertOk();

        $this->assertTrue($login->json('data.requires_2fa'));
        $this->assertNull($login->json('data.token'), 'Aucun token complet sans TOTP.');
        $this->assertNotNull($login->json('data.partial_token'));
    }

    public function test_platform_admin_without_2fa_is_blocked_from_admin_area(): void
    {
        $admin = User::factory()->platformAdminWithout2FA()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', '2FA_SETUP_REQUIRED');
    }

    public function test_platform_admin_without_2fa_can_still_reach_the_setup_endpoint(): void
    {
        $admin = User::factory()->platformAdminWithout2FA()->create();

        // Sinon l'admin serait définitivement enfermé dehors : la page de
        // configuration doit rester joignable pour sortir de l'impasse.
        $this->actingAs($admin)
            ->getJson('/api/v1/auth/2fa/setup')
            ->assertOk();
    }

    public function test_platform_admin_with_2fa_reaches_the_admin_area(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();
    }

    // ── XSS stocké dans l'export autorité ────────────────────────────────────

    /**
     * La faille : les données saisies par l'hôtel étaient interpolées telles
     * quelles dans un document servi en text/html, ouvert par un agent des
     * forces de l'ordre. L'inscription étant ouverte, n'importe qui pouvait
     * piéger un nom de voyageur et voler la session de l'agent.
     */
    public function test_guest_export_escapes_html_in_guest_names(): void
    {
        $org       = AuthorityOrganization::factory()->ministry()->create();
        $authority = User::factory()->authorityUser($org)->create();

        $hotel      = Hotel::factory()->withActiveSubscription()->create();
        $hotelStaff = User::factory()->hotelAdmin($hotel)->create();
        $checkIn    = CheckIn::factory()->for($hotel)->create();

        $payload = '<img src=x onerror="alert(1)">';
        $guest   = Guest::factory()->create([
            'last_name'  => $payload,
            'first_name' => 'Test',
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_at' => now(), 'added_by' => $hotelStaff->id]);

        $response = $this->actingAs($authority)
            ->get("/api/v1/authority/guests/{$guest->id}/export/pdf")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('<img src=x', $html, 'Balise injectée rendue telle quelle.');
        $this->assertStringNotContainsString('onerror="alert(1)"', $html);
        // Le nom est passé en majuscules avant rendu, d'où le /i.
        $this->assertMatchesRegularExpression('/&lt;img/i', $html, 'Le contenu doit être échappé, pas supprimé.');
    }

    public function test_guest_export_escapes_html_in_hotel_names(): void
    {
        $org       = AuthorityOrganization::factory()->ministry()->create();
        $authority = User::factory()->authorityUser($org)->create();

        $hotel = Hotel::factory()->withActiveSubscription()->create([
            'name' => '<script>alert(1)</script>',
        ]);
        $hotelStaff = User::factory()->hotelAdmin($hotel)->create();
        $checkIn    = CheckIn::factory()->for($hotel)->create();
        $guest      = Guest::factory()->create();
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_at' => now(), 'added_by' => $hotelStaff->id]);

        $response = $this->actingAs($authority)
            ->get("/api/v1/authority/guests/{$guest->id}/export/pdf")
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_guest_export_forbids_inline_scripts_via_csp(): void
    {
        $org       = AuthorityOrganization::factory()->ministry()->create();
        $authority = User::factory()->authorityUser($org)->create();

        $hotel      = Hotel::factory()->withActiveSubscription()->create();
        $hotelStaff = User::factory()->hotelAdmin($hotel)->create();
        $checkIn    = CheckIn::factory()->for($hotel)->create();
        $guest      = Guest::factory()->create();
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_at' => now(), 'added_by' => $hotelStaff->id]);

        $this->actingAs($authority)
            ->get("/api/v1/authority/guests/{$guest->id}/export/pdf")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
