<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Matrice rôle × endpoint : aucun rôle ne doit franchir la porte d'un autre.
 *
 * ── Ce que ce test apporte par rapport à RbacTest ────────────────────────
 *
 * `RbacTest` vérifie des endpoints choisis. Celui-ci ÉNUMÈRE toutes les routes
 * `admin/*` depuis le routeur et les présente à CHACUN des trois rôles
 * inférieurs. La différence n'est pas cosmétique : une route
 * d'administration ajoutée demain sans sa garde est refusée par ce test sans
 * que personne ait eu à penser à l'ajouter à une liste.
 *
 * C'est exactement le mode de panne qu'une liste écrite à la main ne peut pas
 * couvrir — on n'oublie pas de tester ce à quoi on pense, on oublie de tester
 * ce à quoi on ne pense pas.
 *
 * ── Pourquoi 401/403 et pas seulement « échec » ─────────────────────────
 *
 * Une route d'administration qui répond 404 à un réceptionniste serait sûre
 * mais suspecte : cela voudrait dire qu'elle a exécuté sa logique avant de
 * refuser. On exige donc un refus d'AUTORISATION, prononcé par la garde.
 */
class RoleEscalationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
    }

    /** @return array<string,User> */
    private function lowerRoles(): array
    {
        $org = AuthorityOrganization::create([
            'name' => 'Poste matrice', 'type' => 'police', 'is_active' => true,
        ]);
        // `authorityUser()` crée déjà le profil : `firstOrCreate` évite la
        // violation d'unicité sans supposer ce que fait la fabrique.
        $agent = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $agent->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );

        return [
            'hotel_admin' => User::factory()->hotelAdmin($this->hotel)->create(),
            'receptionist' => User::factory()->receptionist($this->hotel)->create(),
            'authority_user' => $agent,
        ];
    }

    /**
     * @return array<int,array{0:string,1:string}> couples [méthode, chemin]
     */
    private function adminRoutes(): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/admin/')) {
                continue;
            }

            // Un identifiant qui n'existe pas suffit : la garde de rôle passe
            // AVANT le contrôleur, donc avant toute recherche en base. Si un
            // rôle inférieur obtient 404 plutôt que 403, c'est précisément le
            // défaut que ce test cherche.
            $path = preg_replace('/\{[^}]+\}/', '00000000-0000-4000-8000-000000000000', $uri);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = [$method, '/'.$path];
            }
        }

        return $out;
    }

    public function test_no_admin_route_is_reachable_by_a_lower_role(): void
    {
        $routes = $this->adminRoutes();
        $this->assertGreaterThan(80, count($routes), 'la matrice doit couvrir toutes les routes admin');

        $breaches = [];

        foreach ($this->lowerRoles() as $roleName => $user) {
            foreach ($routes as [$method, $path]) {
                $status = $this->actingAs($user)->json($method, $path)->status();

                // 403 = refusé par la garde. 401 = jeton insuffisant (2FA).
                // 429 = limiteur, qui refuse aussi. Tout le reste signifie que
                // la requête est allée plus loin qu'elle n'aurait dû.
                if (! in_array($status, [401, 403, 429], true)) {
                    $breaches[] = sprintf('%s : %s %s -> %d', $roleName, $method, $path, $status);
                }
            }
        }

        $this->assertSame(
            [],
            $breaches,
            "Des routes d'administration répondent à un rôle inférieur :\n  ".implode("\n  ", $breaches),
        );
    }

    /**
     * L'endpoint de gestion des utilisateurs est le chemin naturel d'une
     * élévation de privilèges : c'est le seul que l'hôtelier contrôle et qui
     * ÉCRIT un rôle.
     */
    public function test_a_hotel_admin_cannot_mint_a_platform_admin(): void
    {
        $admin = User::factory()->hotelAdmin($this->hotel)->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/hotel/users', [
                'first_name' => 'Escalade',
                'last_name' => 'Tentative',
                'email' => 'escalade@example.test',
                'role' => 'platform_admin',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'role');

        $this->assertDatabaseMissing('users', ['email' => 'escalade@example.test']);
    }

    public function test_a_hotel_admin_cannot_promote_an_existing_user_to_platform_admin(): void
    {
        $admin = User::factory()->hotelAdmin($this->hotel)->create();
        $target = User::factory()->receptionist($this->hotel)->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/hotel/users/{$target->id}", ['role' => 'platform_admin'])
            ->assertStatus(422);

        $this->assertTrue($target->fresh()->hasRole('receptionist'));
        $this->assertFalse($target->fresh()->hasRole('platform_admin'));
    }

    /**
     * L'affectation d'établissements est l'autre écriture sensible : pouvoir
     * rattacher quelqu'un à l'établissement d'une AUTRE organisation
     * reviendrait à s'y donner accès par personne interposée.
     */
    public function test_a_hotel_admin_cannot_attach_a_user_to_a_foreign_property(): void
    {
        $admin = User::factory()->hotelAdmin($this->hotel)->create();
        $target = User::factory()->receptionist($this->hotel)->create();
        $foreign = Hotel::factory()->withActiveSubscription()->create();

        $this->actingAs($admin)
            ->patchJson("/api/v1/hotel/users/{$target->id}", ['hotel_ids' => [$foreign->id]])
            ->assertStatus(422);

        $this->assertFalse(
            $target->fresh()->hotels()->where('hotels.id', $foreign->id)->exists(),
            'un utilisateur a été rattaché à un établissement hors de son organisation',
        );
    }

    /**
     * La boîte de réception des autorités porte des échanges avec des postes
     * de police. Elle est réservée à `platform_admin` — y compris vis-à-vis
     * des comptes AUTORITÉ, qui sont pourtant les interlocuteurs de ces
     * échanges : ils ne doivent pas lire les fils des autres.
     */
    public function test_the_authority_inbox_is_closed_to_authority_and_hotel_roles(): void
    {
        foreach ($this->lowerRoles() as $roleName => $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/admin/whatsapp/inbox')
                ->assertForbidden("le rôle $roleName a atteint la boîte de réception");
        }
    }
}
