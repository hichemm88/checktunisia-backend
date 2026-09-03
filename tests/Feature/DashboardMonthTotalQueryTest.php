<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le compteur « check-ins ce mois-ci » du dashboard ne doit coûter que ce
 * qu'il affiche, pas l'historique entier de l'établissement.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * Le contrôleur comptait avec `whereMonth('created_at', ...)->whereYear(...)`.
 * Ce sont des fonctions appliquées à la colonne : un index b-tree ordinaire
 * sur `created_at` ne peut pas y répondre par une recherche bornée — Postgres
 * devait relire CHAQUE check-in jamais enregistré par l'établissement pour ne
 * garder que ceux du mois courant. Le même défaut, sur la même page, que
 * celui déjà corrigé pour `buildOccupancy()` (voir DashboardOccupancyWindowTest) :
 * une requête sans borne basse réelle, dont le coût grandit avec le volume
 * total et non avec l'écran affiché — un établissement multi-propriétés avec
 * plusieurs années d'activité est le premier à le sentir.
 *
 * ── Ce que ces tests verrouillent ───────────────────────────────────────
 *
 *  1. Le RÉSULTAT ne change pas : seuls les check-ins du mois courant comptent.
 *  2. La REQUÊTE reste bornée : elle compare directement `created_at` (via
 *     whereBetween), jamais via une fonction (whereMonth/whereYear/EXTRACT)
 *     qui empêcherait l'index `idx_check_ins_hotel_created` de la satisfaire
 *     par une recherche.
 */
class DashboardMonthTotalQueryTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['room_count' => 10]);
        $this->user = User::factory()->hotelAdmin($this->hotel)->create();
    }

    public function test_only_check_ins_created_this_month_are_counted(): void
    {
        $this->checkInCreatedAt(now()->startOfMonth()->addDays(2));
        $this->checkInCreatedAt(now()->startOfMonth()->addDays(5));

        // Hors mois courant — un an d'historique, à ne jamais compter ici.
        foreach (range(1, 24) as $i) {
            $this->checkInCreatedAt(now()->copy()->subMonths($i));
        }

        $total = $this->actingAs($this->user)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk()
            ->json('data.month.check_ins_total');

        $this->assertSame(2, $total, 'seuls les check-ins créés ce mois-ci doivent compter');
    }

    public function test_the_month_total_query_is_a_bounded_range_not_a_function_on_the_column(): void
    {
        $this->checkInCreatedAt(now());

        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson('/api/v1/hotel/dashboard')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $monthTotalQueries = array_values(array_filter(
            $queries,
            fn ($q) => str_starts_with(strtolower(trim($q['query'])), 'select count')
                && str_contains($q['query'], 'check_ins')
                && str_contains(strtolower($q['query']), 'created_at')
                && ! str_contains(strtolower($q['query']), 'group by'),
        ));

        $this->assertNotEmpty($monthTotalQueries, 'la requête du total mensuel doit être identifiable');

        foreach ($monthTotalQueries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString(
                'extract',
                $sql,
                'whereMonth/whereYear compile en EXTRACT() sur Postgres : un index b-tree '
                .'sur created_at ne peut pas répondre à une fonction de la colonne, ce qui '
                .'oblige à relire tout l\'historique de check-ins à chaque chargement.',
            );
            $this->assertStringContainsString(
                'between',
                $sql,
                'le total du mois doit comparer created_at directement (whereBetween), pas '
                .'via une fonction, pour rester satisfiable par un index borné',
            );
        }
    }

    private function checkInCreatedAt(mixed $createdAt): CheckIn
    {
        return CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->user->id,
            'created_at' => $createdAt,
        ]);
    }
}
