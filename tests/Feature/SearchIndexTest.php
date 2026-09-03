<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Index de recherche trigram (DB-01).
 *
 * Ces tests protègent une optimisation invisible : sans eux, une migration
 * future pourrait supprimer les index — les recherches continueraient de
 * fonctionner, simplement 8 à 180 fois plus lentement, et personne ne le
 * remarquerait avant que la production ne soit à genoux.
 */
class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_pg_trgm_extension_is_installed(): void
    {
        $installed = DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'pg_trgm'");

        $this->assertNotNull($installed, "L'extension pg_trgm est absente : les index trigram ne peuvent pas exister.");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function expectedIndexes(): array
    {
        return [
            'prénom du voyageur'   => ['guests', 'idx_guests_first_name_trgm'],
            'nom du voyageur'      => ['guests', 'idx_guests_last_name_trgm'],
            'numéro de document'   => ['travel_documents', 'idx_travel_docs_number_trgm'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expectedIndexes')]
    public function test_trigram_index_exists(string $table, string $index): void
    {
        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
            [$table, $index]
        );

        $this->assertNotNull($row, "Index {$index} absent de {$table}.");
        $this->assertStringContainsString('gin', strtolower($row->indexdef), "{$index} n'est pas un index GIN.");
        $this->assertStringContainsString('gin_trgm_ops', strtolower($row->indexdef), "{$index} n'utilise pas gin_trgm_ops : il ne servira pas pour ILIKE '%…%'.");
    }

    /**
     * Le test qui compte vraiment : la recherche autorité doit continuer de
     * renvoyer les bons résultats. Un index cassé ou un opérateur mal choisi
     * se verrait ici, pas dans un contrôle de métadonnées.
     *
     * Les voyageurs portent un séjour DÉCLARÉ (`active`, via
     * CheckIn::factory()->withGuest()) : depuis le correctif P9 (un voyageur
     * dont le séjour n'était que « draft » restait visible côté autorité),
     * un Guest créé seul, sans check-in finalisé, est invisible par
     * construction. Ce test mesure le trigram, pas cette règle — le fixture
     * s'aligne dessus plutôt que d'en devenir un faux négatif.
     */
    public function test_partial_name_search_still_returns_matches(): void
    {
        $org       = AuthorityOrganization::factory()->ministry()->create();
        $authority = User::factory()->authorityUser($org)->create();
        $hotel     = Hotel::factory()->withActiveSubscription()->create();

        CheckIn::factory()->for($hotel)->withGuest('Mohamed', 'Mathlouthi')->create();
        CheckIn::factory()->for($hotel)->withGuest('Fatma', 'Trabelsi')->create();

        $response = $this->actingAs($authority)
            ->getJson('/api/v1/authority/search?last_name=athlou')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('last_name')->all();

        $this->assertContains('Mathlouthi', $names, "La recherche partielle en milieu de chaîne ne trouve plus le voyageur.");
        $this->assertNotContains('Trabelsi', $names, "La recherche partielle remonte des résultats non pertinents.");
    }

    public function test_partial_name_search_is_case_insensitive(): void
    {
        $org       = AuthorityOrganization::factory()->ministry()->create();
        $authority = User::factory()->authorityUser($org)->create();
        $hotel     = Hotel::factory()->withActiveSubscription()->create();

        CheckIn::factory()->for($hotel)->withGuest('Mohamed', 'Mathlouthi')->create();

        $response = $this->actingAs($authority)
            ->getJson('/api/v1/authority/search?last_name=MATHLOU')
            ->assertOk();

        $this->assertNotEmpty($response->json('data'), 'La recherche doit rester insensible à la casse (ILIKE).');
    }
}
