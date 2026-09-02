<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WatchlistEntry;
use App\Models\WatchlistHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Cloisonnement entre postes de police : un agent de Tunis ne doit rien
 * pouvoir tirer de Sfax.
 *
 * ── Ce qui n'était pas couvert ──────────────────────────────────────────
 *
 * `AuthorityScopingTest` couvre bien la watchlist et la recherche. Il ne
 * touche PAS les chemins par identifiant direct — profil d'un voyageur,
 * export PDF de ce profil, séjours d'un établissement, accusé de réception
 * d'une alerte. Or ce sont exactement ceux qu'un agent peut atteindre en
 * changeant un identifiant dans l'URL, sans passer par aucune recherche.
 *
 * L'export PDF mérite sa place à lui seul : c'est un second chemin vers les
 * mêmes données, écrit à un autre moment, et c'est le genre d'endroit où un
 * cloisonnement se perd sans que rien ne le signale — comme les trois règles
 * de validation qui avaient disparu entre `store` et `update`.
 *
 * ── Ce que « fuite » veut dire ici ──────────────────────────────────────
 *
 * Les données en jeu sont des numéros de passeport et des adresses de séjour
 * de personnes physiques, consultés par des agents assermentés. Une réponse
 * 200 hors périmètre n'est pas un défaut d'affichage : c'est un accès non
 * autorisé à un fichier de police.
 */
class AuthorityCrossOrgMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,mixed> Poste de Tunis — celui qui appelle. */
    private array $tunis;

    /** @var array<string,mixed> Poste de Sfax — celui qu'on tente d'atteindre. */
    private array $sfax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tunis = $this->makeGovernorate('Tunis');
        $this->sfax = $this->makeGovernorate('Sfax');
    }

    /**
     * Un gouvernorat complet : son poste de police, son agent, son
     * établissement, un voyageur qui y a séjourné, une entrée de watchlist et
     * une alerte.
     *
     * @return array<string,mixed>
     */
    private function makeGovernorate(string $governorate): array
    {
        $org = AuthorityOrganization::create([
            'name' => "Poste de $governorate",
            'type' => 'police',
            'governorate' => $governorate,
            'is_active' => true,
        ]);

        $agent = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $agent->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );

        $hotel = Hotel::factory()->withActiveSubscription()->inGovernorate($governorate)->create([
            'name' => "Hotel $governorate",
        ]);

        $guest = Guest::factory()->create(['last_name' => strtoupper($governorate)]);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'PASS-'.strtoupper($governorate),
            'issuing_country_code' => 'TUN',
        ]);

        $checkIn = CheckIn::factory()->for($hotel)->create([
            'created_by' => User::factory()->hotelAdmin($hotel)->create()->id,
            'status' => 'active',
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $checkIn->created_by]);

        $entry = WatchlistEntry::factory()->create([
            'organization_id' => $org->id,
            'added_by' => $agent->id,
        ]);

        $hit = WatchlistHit::factory()->create([
            'watchlist_entry_id' => $entry->id,
            'guest_id' => $guest->id,
            'check_in_id' => $checkIn->id,
            'hotel_id' => $hotel->id,
        ]);

        return compact('org', 'agent', 'hotel', 'guest', 'checkIn', 'entry', 'hit');
    }

    /**
     * Le portail autorité impose une 2FA confirmée : sans jeton complet, tout
     * répond 403 pour une raison qui n'a rien à voir avec le cloisonnement, et
     * le test croirait avoir prouvé quelque chose.
     */
    private function actingAsAgent(User $agent): static
    {
        $agent->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $this->actingAs($agent);
    }

    // ── La matrice ───────────────────────────────────────────────────────────

    public function test_an_officer_cannot_reach_another_governorate_by_id(): void
    {
        $sfax = $this->sfax;

        $probes = [
            ['GET', "/api/v1/authority/guests/{$sfax['guest']->id}", 'profil voyageur'],
            ['GET', "/api/v1/authority/guests/{$sfax['guest']->id}/export/pdf", 'export PDF du profil'],
            ['GET', "/api/v1/authority/hotels/{$sfax['hotel']->id}", 'fiche établissement'],
            ['GET', "/api/v1/authority/hotels/{$sfax['hotel']->id}/check-ins", 'séjours de l\'établissement'],
            ['PATCH', "/api/v1/authority/watchlist/{$sfax['entry']->id}", 'entrée de watchlist'],
            ['DELETE', "/api/v1/authority/watchlist/{$sfax['entry']->id}", 'suppression de watchlist'],
            ['POST', "/api/v1/authority/security-alerts/{$sfax['hit']->id}/acknowledge", 'accusé d\'alerte'],
            ['POST', "/api/v1/authority/security-alerts/{$sfax['hit']->id}/seen", 'alerte vue'],
        ];

        $leaks = [];

        foreach ($probes as [$method, $path, $label]) {
            $status = $this->actingAsAgent($this->tunis['agent'])
                ->json($method, $path, ['reason' => 'probe', 'severity' => 'eleve'])
                ->status();

            if ($status < 400) {
                $leaks[] = sprintf('%s (%s %s) -> %d', $label, $method, $path, $status);
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "Un agent de Tunis atteint des données de Sfax :\n  ".implode("\n  ", $leaks),
        );
    }

    /**
     * Contre-épreuve indispensable : si le cloisonnement refusait TOUT, les
     * tests ci-dessus passeraient sans rien prouver. Un agent doit voir son
     * propre gouvernorat.
     */
    public function test_an_officer_does_reach_their_own_governorate(): void
    {
        $tunis = $this->tunis;

        $this->actingAsAgent($tunis['agent'])
            ->getJson("/api/v1/authority/guests/{$tunis['guest']->id}")
            ->assertOk()
            ->assertJsonPath('data.last_name', 'TUNIS');

        $this->actingAsAgent($tunis['agent'])
            ->get("/api/v1/authority/guests/{$tunis['guest']->id}/export/pdf")
            ->assertOk();
    }

    /**
     * La liste des alertes ne doit pas déborder non plus : le cloisonnement
     * par identifiant ne sert à rien si l'index sert déjà tout le pays.
     */
    public function test_the_alert_list_stops_at_the_governorate_border(): void
    {
        $body = $this->actingAsAgent($this->tunis['agent'])
            ->getJson('/api/v1/authority/security-alerts')
            ->assertOk()
            ->json();

        $ids = collect(data_get($body, 'data', []))->pluck('id')->all();

        $this->assertNotContains(
            $this->sfax['hit']->id,
            $ids,
            'une alerte de Sfax apparaît dans la liste d\'un agent de Tunis',
        );
    }

    /**
     * Le ministère voit tout — c'est le contrat métier. Le vérifier évite de
     * « corriger » un jour le cloisonnement en cassant la supervision
     * nationale.
     */
    public function test_the_ministry_still_sees_every_governorate(): void
    {
        $ministry = AuthorityOrganization::create([
            'name' => 'Ministère de l\'Intérieur',
            'type' => 'ministry',
            'is_active' => true,
        ]);
        $official = User::factory()->authorityUser($ministry)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $official->id],
            ['organization_id' => $ministry->id, 'authorized_at' => now()],
        );

        foreach ([$this->tunis, $this->sfax] as $gov) {
            $this->actingAsAgent($official)
                ->getJson("/api/v1/authority/guests/{$gov['guest']->id}")
                ->assertOk();
        }
    }
}
