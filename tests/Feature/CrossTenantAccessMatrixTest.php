<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Matrice d'accès inter-locataires — la question posée à CHAQUE endpoint.
 *
 * ── Pourquoi une matrice, et pas des cas choisis ────────────────────────
 *
 * Les tests de sécurité existants (RbacTest, SecurityHardeningTest,
 * AuthorityScopingTest) vérifient des situations choisies une par une. C'est
 * utile, et c'est structurellement incapable d'attraper l'endpoint qu'on a
 * oublié : un test écrit à la main ne couvre que les routes auxquelles son
 * auteur a pensé le jour où il l'a écrit.
 *
 * Ce test-ci ÉNUMÈRE les routes depuis le routeur lui-même. Une route ajoutée
 * demain entre automatiquement dans la matrice — et si elle laisse fuir les
 * données d'un autre établissement, elle échoue sans que personne ait eu à y
 * penser. C'est la seule forme qui vieillit bien.
 *
 * ── Le protocole ────────────────────────────────────────────────────────
 *
 * Deux locataires COMPLÈTEMENT séparés : deux organisations, deux
 * établissements, leurs utilisateurs, leurs séjours, leurs voyageurs, leurs
 * chambres, leurs scans. On s'authentifie chez A, on appelle chaque route
 * paramétrée en y injectant les identifiants de B.
 *
 * Le verdict attendu n'est PAS « 404 » : c'est « pas de succès ». Un 403
 * (refus explicite) et un 404 (on ne dit même pas que la ressource existe)
 * sont deux réponses défendables — la seconde en dit moins, donc vaut mieux.
 * Un 2xx, lui, signifie qu'un hôtelier vient de lire ou d'écrire chez un
 * concurrent.
 */
class CrossTenantAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> Locataire A — celui qui appelle. */
    private array $a;

    /** @var array<string,mixed> Locataire B — celui dont on tente de voler les données. */
    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant('Dar Alpha');
        $this->b = $this->makeTenant('Dar Beta');
    }

    /**
     * Un locataire complet et autonome : rien n'est partagé avec l'autre.
     *
     * @return array<string,mixed>
     */
    private function makeTenant(string $name): array
    {
        $org = Organization::create([
            'name' => $name.' SARL',
            'entity_type' => 'company',
            'contact_email' => strtolower(str_replace(' ', '', $name)).'@example.test',
            'status' => 'active',
        ]);
        $hotel = Hotel::factory()->withActiveSubscription()->create([
            'organization_id' => $org->id,
            'name' => $name,
            'room_count' => 10,
        ]);

        /*
         * Abonnement au niveau ORGANISATION, et pas seulement de
         * l'etablissement. `EnsureActiveSubscription` interroge l'organisation
         * des qu'il y en a une : sans cette ligne, tous les appels de ce test
         * seraient refuses en 403 SUBSCRIPTION_INACTIVE — un refus, certes,
         * mais pour la MAUVAISE raison. La matrice croirait alors verifier le
         * cloisonnement alors qu'elle ne verifierait qu'une porte fermee plus
         * haut, et laisserait passer une vraie fuite.
         */
        Subscription::create([
            'organization_id' => $org->id,
            'plan_id' => SubscriptionPlan::firstOrCreate(
                ['slug' => 'standard'],
                [
                    'name' => 'Standard', 'min_rooms' => 1, 'max_rooms' => null,
                    'price_monthly' => 99.000, 'currency' => 'TND', 'features' => [],
                    'is_active' => true, 'sort_order' => 1,
                ],
            )->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'started_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
            'auto_renew' => true,
            'metadata' => [],
        ]);

        $admin = User::factory()->hotelAdmin($hotel)->create(['organization_id' => $org->id]);
        $receptionist = User::factory()->receptionist($hotel)->create(['organization_id' => $org->id]);

        $room = Room::factory()->for($hotel)->create();

        $checkIn = CheckIn::factory()->for($hotel)->create([
            'created_by' => $admin->id,
            'status' => 'draft',
            'room_id' => $room->id,
        ]);

        $guest = Guest::factory()->create(['last_name' => strtoupper($name)]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $admin->id]);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'DOC-'.strtoupper(substr($name, -5)),
            'issuing_country_code' => 'TUN',
        ]);

        $scan = DocumentScan::create([
            'check_in_id' => $checkIn->id,
            'file_path' => 'scans/'.$hotel->id.'.jpg',
            'file_hash' => hash('sha256', $name),
            'mime_type' => 'image/jpeg',
            'ocr_status' => 'completed',
            'uploaded_by' => $admin->id,
        ]);

        return compact('org', 'hotel', 'admin', 'receptionist', 'room', 'checkIn', 'guest', 'scan');
    }

    /**
     * Identifiants du locataire B, par nom de paramètre de route.
     *
     * @return array<string,string>
     */
    private function foreignIds(): array
    {
        return [
            'id' => $this->b['checkIn']->id,       // surchargé par route ci-dessous
            'check_in_id' => $this->b['checkIn']->id,
            'guest_id' => $this->b['guest']->id,
            'scan_id' => $this->b['scan']->id,
            'scanId' => $this->b['scan']->id,
            'roomId' => $this->b['room']->id,
            'hotel_id' => $this->b['hotel']->id,
            'host_id' => $this->b['org']->id,
        ];
    }

    /**
     * `{id}` ne désigne pas la même chose selon la route : un séjour, une
     * chambre, un utilisateur, une propriété. Le deviner depuis l'URI est ce
     * qui rend la matrice juste — un mauvais identifiant produirait un 404
     * pour la mauvaise raison, et le test passerait en croyant avoir vérifié
     * quelque chose.
     */
    private function idFor(string $uri): ?string
    {
        return match (true) {
            str_contains($uri, '/check-ins/') => $this->b['checkIn']->id,
            str_contains($uri, '/rooms/') => $this->b['room']->id,
            str_contains($uri, '/properties/') => $this->b['hotel']->id,
            str_contains($uri, '/users/') => $this->b['admin']->id,
            default => null,
        };
    }

    // ── La matrice ───────────────────────────────────────────────────────────

    public function test_no_hotel_endpoint_serves_another_tenants_resource(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/hotel/') && str_contains($r->uri(), '{'))
            ->values();

        $this->assertGreaterThan(20, $routes->count(), 'la matrice doit couvrir toutes les routes paramétrées');

        $ids = $this->foreignIds();
        $leaks = [];
        $inconclusive = [];
        $covered = 0;

        foreach ($routes as $route) {
            $uri = $route->uri();

            // Routes de paiement / facture : leurs identifiants n'appartiennent
            // pas au jeu de données de ce test, un appel produirait un 404 qui
            // ne prouverait rien. Elles sont couvertes par leurs propres tests.
            if (str_contains($uri, '/payments/') || str_contains($uri, '/invoices/')) {
                continue;
            }

            $path = $uri;
            foreach ($route->parameterNames() as $param) {
                $value = $param === 'id' ? ($this->idFor($uri) ?? $ids['id']) : ($ids[$param] ?? null);
                if ($value === null) {
                    continue 2; // paramètre inconnu : on ne fabrique pas un faux verdict
                }
                $path = str_replace('{'.$param.'}', $value, $path);
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $covered++;

                $response = $this->actingAs($this->a['admin'])
                    ->json($method, '/'.$path, $this->payloadFor($method));

                if ($response->status() < 400) {
                    $leaks[] = sprintf('%s /%s -> %d', $method, $path, $response->status());
                }

                /*
                 * 422 = VALIDATION. Ce n'est PAS une preuve de cloisonnement.
                 *
                 * Un contrôleur qui valide AVANT d'autoriser répond 422 à une
                 * requête inter-locataires — et une matrice qui se contente de
                 * « statut >= 400 » le compterait comme sûr. Le trou resterait
                 * ouvert, masqué par un champ manquant dans la charge de test.
                 *
                 * On les recense donc à part : chacun doit être justifié, pas
                 * avalé en silence.
                 */
                if ($response->status() === 422) {
                    $inconclusive[] = sprintf('%s /%s', $method, $path);
                }
            }
        }

        $this->assertGreaterThan(20, $covered, 'la matrice doit réellement appeler les routes');

        $this->assertSame(
            [],
            $leaks,
            "Des routes servent les données d'un AUTRE établissement :\n  ".implode("\n  ", $leaks),
        );

        $this->assertSame(
            [],
            $inconclusive,
            "Ces routes refusent pour VALIDATION, pas pour autorisation — le cloisonnement n'y est pas prouvé :\n  "
            .implode("\n  ", $inconclusive),
        );
    }

    /**
     * Le réceptionniste est le rôle le plus bas côté hôtelier : ce qui est
     * fermé à l'administrateur d'un autre établissement doit l'être pour lui
     * aussi. Le vérifier séparément évite de supposer que les deux rôles
     * empruntent le même chemin d'autorisation — ils n'y sont pas obligés.
     */
    public function test_a_receptionist_cannot_reach_another_tenant_either(): void
    {
        $b = $this->b;

        $probes = [
            ['GET', "/api/v1/hotel/check-ins/{$b['checkIn']->id}"],
            ['PATCH', "/api/v1/hotel/check-ins/{$b['checkIn']->id}"],
            ['POST', "/api/v1/hotel/check-ins/{$b['checkIn']->id}/guests"],
            ['PATCH', "/api/v1/hotel/check-ins/{$b['checkIn']->id}/guests/{$b['guest']->id}"],
            ['DELETE', "/api/v1/hotel/check-ins/{$b['checkIn']->id}/guests/{$b['guest']->id}"],
            ['GET', "/api/v1/hotel/scans/{$b['scan']->id}/status"],
        ];

        foreach ($probes as [$method, $path]) {
            $this->actingAs($this->a['receptionist'])
                ->json($method, $path, $this->payloadFor($method))
                ->assertStatus(404, "réceptionniste A -> $method $path");
        }
    }

    /**
     * Le locataire ACTIF vient du jeton, pas d'un en-tête choisi par le client.
     *
     * `ResolveTenant` lit un en-tête `X-Hotel-Id` pour les comptes
     * multi-établissements. Si cet en-tête était cru sur parole, tout le
     * cloisonnement s'écroulerait : il suffirait de le poser à l'identifiant
     * du voisin.
     */
    public function test_the_active_property_header_cannot_point_at_a_foreign_hotel(): void
    {
        $response = $this->actingAs($this->a['admin'])
            ->withHeader('X-Hotel-Id', $this->b['hotel']->id)
            ->getJson('/api/v1/hotel/check-ins');

        // Soit l'en-tête est refusé, soit il est ignoré — mais les séjours
        // servis doivent rester ceux de A.
        if ($response->status() < 400) {
            $names = collect($response->json('data'))->pluck('guest_name')->filter()->implode(' ');
            $this->assertStringNotContainsString(
                'DAR BETA',
                strtoupper($names),
                'un en-tête choisi par le client a ouvert les séjours du voisin',
            );
        }
    }

    /**
     * Charge VALIDE pour les écritures.
     *
     * Une charge incomplète ferait répondre 422 avant que l'autorisation ne
     * soit consultée : le test croirait avoir prouvé le cloisonnement alors
     * qu'il n'aurait prouvé qu'un champ manquant. On envoie donc de quoi
     * satisfaire les validateurs de tous les endpoints d'écriture visés — les
     * champs superflus sont ignorés par `validate()`.
     */
    private function payloadFor(string $method): array
    {
        if (! in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            return [];
        }

        return [
            // Voyageur
            'first_name' => 'Probe',
            'last_name' => 'Probe',
            'date_of_birth' => '1990-01-01',
            'sex' => 'M',
            'nationality_code' => 'TUN',
            'document' => [
                'type' => 'passport',
                'document_number' => 'PROBE-XT-1',
                'issuing_country_code' => 'TUN',
            ],
            // Chambre
            'number' => 'P-1',
            'type' => 'double',
            'capacity' => 2,
            'status' => 'available',
            // Séjour
            'notes' => 'probe',
            'adults_count' => 1,
        ];
    }
}
