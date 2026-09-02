<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le tableau de bord ne doit coûter que ce qu'il AFFICHE, pas ce que
 * l'établissement a d'historique.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * `buildOccupancy` n'avait qu'une borne HAUTE sur les dates. Il chargeait donc
 * tous les séjours de l'établissement depuis l'ouverture, en mémoire, pour
 * n'en garder que ceux qui touchent une fenêtre de 7 ou 30 jours — et il est
 * appelé deux fois par affichage. L'audit de performance a mesuré 3,4 s p50 à
 * 3 000 fiches et 6,6 s à 6 000 : une croissance sans plafond, sur l'écran le
 * plus consulté du produit.
 *
 * ── Ce que ces tests verrouillent ───────────────────────────────────────
 *
 *  1. Le RÉSULTAT ne change pas. La borne basse est sûre précisément parce
 *     qu'elle n'écarte que des séjours qui ne comptaient nulle part : un
 *     départ antérieur au début de la fenêtre ne peut occuper aucune de ses
 *     nuits. Un test qui compare le taux avec et sans historique ancien le
 *     démontre au lieu de le supposer.
 *  2. La REQUÊTE reste bornée. Sans cette seconde vérification, quelqu'un
 *     pourrait retirer la borne un jour sans qu'aucun test ne bronche — les
 *     chiffres, eux, resteraient justes.
 */
class DashboardOccupancyWindowTest extends TestCase
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

    public function test_ancient_history_does_not_change_the_occupancy_figures(): void
    {
        // Un séjour dans la fenêtre : c'est lui, et lui seul, qui doit compter.
        $this->stay(today()->subDays(3), today()->addDays(2));

        $before = $this->occupancy();

        // Deux ans de séjours terminés, tous antérieurs à la fenêtre. Ils
        // n'occupaient déjà aucune de ses nuits : le résultat doit être
        // identique au caractère près.
        foreach (range(1, 40) as $i) {
            $start = today()->subDays(400 + $i * 5);
            $this->stay($start, $start->copy()->addDays(3), $start->copy()->addDays(3));
        }

        $this->assertSame(
            $before,
            $this->occupancy(),
            'l\'historique ancien ne doit pas peser sur le taux affiché',
        );
    }

    public function test_a_stay_extended_past_its_expected_date_still_counts(): void
    {
        /*
         * Le piège de la borne basse : ce séjour était PRÉVU pour se terminer
         * avant la fenêtre, mais le voyageur est parti plus tard. La boucle
         * choisit la date réelle pour les jours passés — borner uniquement sur
         * la date PRÉVUE l'aurait écarté, et une nuit réellement occupée
         * serait tombée du graphique.
         */
        $this->stay(
            today()->subDays(20),
            today()->subDays(10),   // prévu : hors fenêtre 7 jours
            today()->subDays(1),    // réel : dans la fenêtre
        );

        $rates = collect($this->occupancy())->pluck('rate', 'date');
        $twoDaysAgo = today()->subDays(2)->format('Y-m-d');

        $this->assertSame(10, $rates[$twoDaysAgo] ?? null, 'la nuit réellement occupée doit compter');
    }

    public function test_the_occupancy_query_is_bounded_at_both_ends(): void
    {
        $this->stay(today()->subDays(2), today()->addDays(1));

        DB::enableQueryLog();
        $this->actingAs($this->user)->getJson('/api/v1/hotel/dashboard')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $occupancyQueries = array_values(array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'expected_check_out_date')
                && str_contains($q['query'], 'check_in_date')
                && str_starts_with(strtolower(trim($q['query'])), 'select'),
        ));

        $this->assertNotEmpty($occupancyQueries, 'la requête d\'occupation doit être identifiable');

        foreach ($occupancyQueries as $query) {
            $this->assertStringContainsString(
                'actual_check_out_date',
                $query['query'],
                'la borne basse porte sur les DEUX dates de départ — sans quoi un '
                .'séjour prolongé au-delà de sa date prévue serait écarté à tort',
            );
        }
    }

    public function test_the_property_switcher_does_not_query_per_establishment(): void
    {
        // Le récapitulatif par établissement calculait deux compteurs DANS la
        // boucle. L'écran n'existe que pour les comptes multi-établissements —
        // ceux qui en ont le plus — donc le coût montait avec ce que le client
        // a acheté.
        $others = collect(range(1, 6))->map(function () {
            $h = Hotel::factory()->withActiveSubscription()->create(['room_count' => 5]);
            $this->user->hotels()->attach($h->id);

            return $h;
        });

        foreach ($others as $h) {
            CheckIn::factory()->for($h)->create([
                'created_by' => $this->user->id,
                'status' => 'active',
                'check_in_date' => today()->subDay(),
                'expected_check_out_date' => today()->addDays(2),
                'adults_count' => 2,
                'children_count' => 1,
            ]);
        }

        DB::enableQueryLog();
        $summary = $this->actingAs($this->user)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk()
            ->json('data.properties_summary');
        // Le `group by` distingue le récapitulatif par établissement du KPI
        // « personnes présentes » de l'établissement courant, qui utilise la
        // même somme mais ne porte que sur un seul hôtel.
        $perProperty = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'adults_count + children_count')
                && str_contains(strtolower($q['query']), 'group by'))
            ->count();
        DB::disableQueryLog();

        // Les chiffres restent justes…
        $this->assertCount(7, $summary);
        $forOther = collect($summary)->firstWhere('id', $others->first()->id);
        $this->assertSame(3, $forOther['present']);
        $this->assertSame(20, $forOther['occupancy_rate']);

        // …et une seule requête les produit tous, quel que soit le nombre
        // d'établissements.
        $this->assertSame(1, $perProperty, 'un seul passage pour tous les établissements');
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function stay(mixed $in, mixed $expectedOut, mixed $actualOut = null): CheckIn
    {
        return CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->user->id,
            'status' => $actualOut ? 'completed' : 'active',
            'check_in_date' => $in,
            'expected_check_out_date' => $expectedOut,
            'actual_check_out_date' => $actualOut,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function occupancy(): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk()
            ->json('data.occupancy_7d');
    }
}
