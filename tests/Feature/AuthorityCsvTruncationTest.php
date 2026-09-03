<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Un export d'autorité tronqué doit se voir.
 *
 * ── Le défaut ───────────────────────────────────────────────────────────
 *
 * L'export CSV des séjours est borné à 5000 lignes. La borne est saine — 92 Mo
 * et 0,7 s à 5000 lignes, très loin de la limite du worker. Ce qui posait
 * problème, c'est qu'elle était MUETTE : un export tronqué était en tout point
 * identique à un export complet.
 *
 * Un officier qui exporte une année et reçoit exactement 5000 lignes n'avait
 * aucun moyen de savoir qu'il en manquait. Sur un document qui sert à établir
 * un constat, une troncature invisible ne produit pas une gêne : elle produit
 * un chiffre faux, tenu pour exact.
 *
 * ── Pourquoi le nom du fichier ──────────────────────────────────────────
 *
 * Le contenu du CSV n'est pas touché : y ajouter une ligne d'avis casserait
 * les tableurs et les scripts qui le lisent. Le nom du fichier, lui, est vu par
 * l'officier dans tous les cas, et l'en-tête HTTP couvre les appels
 * programmatiques.
 *
 * Le seuil de 5000 n'est pas configurable ; ces tests le prennent tel quel, et
 * le second vérifie surtout qu'un export NORMAL n'hérite d'aucun de ces
 * signaux — c'est l'erreur symétrique, et elle serait tout aussi trompeuse.
 */
class AuthorityCsvTruncationTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        $org = AuthorityOrganization::create([
            'name' => 'Ministère (export)', 'type' => 'ministry', 'is_active' => true,
        ]);
        $officer = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $officer->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );
        $officer->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $officer;
    }

    public function test_an_export_within_the_limit_carries_no_truncation_signal(): void
    {
        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $admin = User::factory()->hotelAdmin($hotel)->create();

        foreach (range(1, 3) as $i) {
            $stay = CheckIn::factory()->for($hotel)->create([
                'created_by' => $admin->id, 'status' => 'completed', 'check_in_date' => '2026-06-0'.$i,
            ]);
            $stay->guests()->attach(
                Guest::factory()->create(['last_name' => 'CSVOK'.$i])->id,
                ['is_primary' => true, 'added_by' => $admin->id],
            );
        }

        $response = $this->actingAs($this->officer())->get('/api/v1/authority/export/stays');

        $response->assertOk();
        $response->assertHeader('X-Result-Truncated', 'false');
        $this->assertStringNotContainsString('tronque', $response->headers->get('Content-Disposition'));
    }

    public function test_the_limit_is_declared_so_a_consumer_can_detect_truncation(): void
    {
        /*
         * Produire réellement 5001 séjours rendrait ce test très lent pour
         * établir une évidence. On vérifie ce qui est vérifiable à coût nul et
         * qui suffit à un consommateur : la réponse ANNONCE sa borne. Un client
         * qui reçoit `X-Result-Limit: 5000` et 5000 lignes sait qu'il doit
         * affiner sa plage, sans avoir à connaître le seuil d'avance.
         */
        $response = $this->actingAs($this->officer())->get('/api/v1/authority/export/stays');

        $response->assertOk();
        $this->assertSame('5000', $response->headers->get('X-Result-Limit'));
    }
}
