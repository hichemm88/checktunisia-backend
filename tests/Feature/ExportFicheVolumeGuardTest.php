<?php

namespace Tests\Feature;

use App\Jobs\ExportPoliceFichesJob;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * L'export de fiches doit refuser une plage qu'il ne peut pas produire.
 *
 * ── Ce qui se passait sans cette garde ──────────────────────────────────
 *
 * La seule limite était une DURÉE — 366 jours. Rien ne bornait la QUANTITÉ.
 * Or le PDF embarque la pièce d'identité de chaque voyageur en data URI, et
 * dompdf doit tenir l'ensemble en mémoire d'un seul tenant. Mesuré sur un
 * worker à 512 Mo : 150 fiches passent (455 Mo), 200 échouent.
 *
 * L'échec est une erreur FATALE de PHP, pas une exception : elle traverse les
 * `catch`, tue le worker, et la file relance un job qui mourra de la même
 * façon. Le manager, lui, a reçu « 202, envoi en cours » et attend un email
 * qui n'arrivera jamais. Aucun écran ne le lui dit.
 *
 * ── Ce que ces tests garantissent ───────────────────────────────────────
 *
 * Que le refus arrive TÔT et qu'il soit ACTIONNABLE — c'est-à-dire qu'il dise
 * combien de fiches la plage contient, pour que la consigne « réduisez la
 * plage » soit applicable plutôt que devinée.
 *
 * Données strictement synthétiques.
 */
class ExportFicheVolumeGuardTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->manager = User::factory()->hotelAdmin($this->hotel)->create();
    }

    private function fiches(int $n): void
    {
        foreach (range(1, $n) as $i) {
            $checkIn = CheckIn::factory()->for($this->hotel)->create([
                'created_by' => $this->manager->id,
                'status' => 'completed',
                'check_in_date' => '2026-06-15',
                'actual_check_out_date' => '2026-06-17',
            ]);
            $guest = Guest::factory()->create(['last_name' => 'VOLSYNTH'.$i]);
            $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $this->manager->id]);
        }
    }

    private function request(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager)->postJson('/api/v1/hotel/exports/police-fiches', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]);
    }

    public function test_a_range_within_the_ceiling_is_accepted(): void
    {
        Bus::fake();
        config(['fiche.export_max_fiches' => 5]);
        $this->fiches(4);

        $this->request()->assertStatus(202);

        Bus::assertDispatched(ExportPoliceFichesJob::class);
    }

    public function test_a_range_beyond_the_ceiling_is_refused_before_anything_is_queued(): void
    {
        Bus::fake();
        config(['fiche.export_max_fiches' => 5]);
        $this->fiches(8);

        $response = $this->request()
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'TOO_MANY_FICHES');

        // Le message doit porter le compte RÉEL : « trop de fiches » sans
        // chiffre n'indique pas de combien réduire la plage.
        $this->assertStringContainsString('8', $response->json('errors.0.message'));
        $this->assertStringContainsString('5', $response->json('errors.0.message'));

        // Rien ne part en file : le refus doit être total, pas cosmétique.
        Bus::assertNotDispatched(ExportPoliceFichesJob::class);
    }

    public function test_the_job_refuses_on_its_own_if_the_range_filled_up_after_dispatch(): void
    {
        /*
         * La demande et l'exécution sont séparées par l'attente en file. Une
         * plage qui inclut aujourd'hui continue de recevoir des check-ins
         * pendant ce temps : la garde du contrôleur peut donc avoir dit oui
         * pour un volume qui, à l'exécution, ne passe plus.
         *
         * On simule cet écart en abaissant le plafond APRÈS coup, ce qui place
         * le job exactement dans la situation « la plage a débordé depuis ».
         */
        Mail::fake();
        $this->fiches(6);
        config(['fiche.export_max_fiches' => 5]);

        (new ExportPoliceFichesJob($this->hotel->id, '2026-06-01', '2026-06-30', 'manager@qayed.local'))->handle();

        // Aucun email : ni le PDF tronqué, ni l'erreur fatale qu'on cherche à
        // éviter.
        Mail::assertNothingSent();
    }

    public function test_both_guards_count_the_same_thing(): void
    {
        /*
         * Le contrôleur et le job doivent compter à l'identique. S'ils
         * divergeaient, le job refuserait des plages que l'écran vient
         * d'accepter — et la panne redeviendrait muette, par un autre chemin.
         *
         * Ils partagent donc `ficheCount()`. Ce test vérifie que ce compte
         * correspond bien aux fiches réellement produites, y compris les règles
         * faciles à perdre de vue : plusieurs voyageurs sur un même séjour
         * comptent chacun pour une fiche, un voyageur supprimé ne compte pas.
         */
        $checkIn = CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->manager->id,
            'status' => 'completed',
            'check_in_date' => '2026-06-15',
        ]);
        foreach (range(1, 3) as $i) {
            $guest = Guest::factory()->create(['last_name' => 'ACCOMP'.$i]);
            $checkIn->guests()->attach($guest->id, ['is_primary' => $i === 1, 'added_by' => $this->manager->id]);
        }

        $this->assertSame(3, ExportPoliceFichesJob::ficheCount($this->hotel->id, '2026-06-01', '2026-06-30'));

        // Un voyageur retiré du registre ne produit plus de fiche.
        Guest::where('last_name', 'ACCOMP3')->first()->delete();

        $this->assertSame(2, ExportPoliceFichesJob::ficheCount($this->hotel->id, '2026-06-01', '2026-06-30'));
    }

    public function test_another_establishments_stays_are_not_counted(): void
    {
        // Le compte sert à autoriser ou refuser : s'il additionnait les séjours
        // d'un autre établissement, il refuserait un export parfaitement
        // légitime — et révélerait au passage l'activité du voisin.
        $other = Hotel::factory()->withActiveSubscription()->create();
        $otherAdmin = User::factory()->hotelAdmin($other)->create();
        $stay = CheckIn::factory()->for($other)->create([
            'created_by' => $otherAdmin->id, 'status' => 'completed', 'check_in_date' => '2026-06-15',
        ]);
        $stay->guests()->attach(Guest::factory()->create()->id, ['is_primary' => true, 'added_by' => $otherAdmin->id]);

        $this->assertSame(0, ExportPoliceFichesJob::ficheCount($this->hotel->id, '2026-06-01', '2026-06-30'));
    }
}
