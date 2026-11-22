<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Room;
use App\Models\WhatsappSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * v1.1 — départs sans intervention pour les fiches créées par l'API : départ
 * automatique quotidien, PATCH /v1/fiches/{id}, et clôture du séjour
 * précédent quand une nouvelle fiche vise une chambre encore occupée.
 */
class PartnerStayLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private array $fixture;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        // 06:00 à Tunis le 10/12/2026 : la tâche a lieu avant les arrivées.
        Carbon::setTestNow(Carbon::parse('2026-12-10 06:00', 'Africa/Tunis'));

        $this->fixture = $this->setUpLinkedPartner();
        ['plaintext' => $this->fixture['plaintext']] = $this->issuePartnerKey($this->fixture['partner'], ApiKey::MODE_TEST);
        $this->room = Room::factory()->for($this->fixture['hotel'])->create();
    }

    /** Déclare un séjour complet via l'API (session + widget + validation) et renvoie la fiche. */
    private function declare(string $ref, string $arrival, string $departure, ?Room $room = null): CheckIn
    {
        $created = $this->withHeaders($this->bearer($this->fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($this->fixture['hotel'], $ref, [['first_name' => 'X']], [
                'arrival_date' => $arrival,
                'departure_date' => $departure,
                'room_id' => ($room ?? $this->room)->id,
            ]))
            ->assertSuccessful();

        parse_str((string) parse_url($created->json('widget_url'), PHP_URL_QUERY), $q);
        $token = $this->getJson('/widget/v1/bootstrap?token='.$q['token'])->assertOk()->json('widget_token');
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/guests', $this->widgetGuestPayload())->assertCreated();
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk();

        return CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
    }

    private function runTask(): void
    {
        $this->artisan('partner-stays:auto-checkout')->assertSuccessful();
    }

    private function openStaysInRoom(?Room $room = null): array
    {
        return CheckIn::where('room_id', ($room ?? $this->room)->id)->whereIn('status', ['draft', 'active'])->pluck('booking_reference')->all();
    }

    private function patchFiche(CheckIn $fiche, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->bearer($this->fixture['plaintext']))->patchJson("/v1/fiches/{$fiche->id}", $body);
    }

    public function test_task_closes_due_api_stays_with_the_same_effects_as_a_manual_departure_and_spares_native_ones(): void
    {
        $due = $this->declare('BK-DUE', '2026-12-07', '2026-12-10');
        $late = $this->declare('BK-LATE', '2026-12-05', '2026-12-08', Room::factory()->for($this->fixture['hotel'])->create());
        $future = $this->declare('BK-FUTURE', '2026-12-09', '2026-12-12', Room::factory()->for($this->fixture['hotel'])->create());
        $native = CheckIn::factory()->for($this->fixture['hotel'])->create([
            'status' => 'active', 'check_in_date' => '2026-12-05', 'expected_check_out_date' => '2026-12-08',
        ]);
        $relayBefore = WhatsappSendLog::count();

        $this->runTask();

        $this->assertSame('completed', $due->fresh()->status);
        $this->assertSame('2026-12-10', $due->fresh()->actual_check_out_date->toDateString());
        $this->assertSame('2026-12-08', $late->fresh()->actual_check_out_date->toDateString());
        $this->assertSame('active', $future->fresh()->status);
        $this->assertSame('active', $native->fresh()->status);
        $this->assertSame([], $this->openStaysInRoom());
        $this->assertSame(1, AuditLog::where('action', 'check_in.checked_out')->where('subject_id', $due->id)->count());
        $this->assertSame($relayBefore, WhatsappSendLog::count(), 'Un départ ne transmet rien.');

        // Rejouer la tâche ne refait rien.
        $this->runTask();
        $this->assertSame(1, AuditLog::where('action', 'check_in.checked_out')->where('subject_id', $due->id)->count());
    }

    public function test_a_task_then_same_day_arrival_of_b(): void
    {
        $a = $this->declare('BK-A', '2026-12-08', '2026-12-10');
        $this->runTask();
        $b = $this->declare('BK-B', '2026-12-10', '2026-12-12');

        $this->assertSame('2026-12-10', $a->fresh()->actual_check_out_date->toDateString());
        $this->assertSame('active', $b->fresh()->status);
        $this->assertSame(['BK-B'], $this->openStaysInRoom());
    }

    public function test_b_arrival_of_b_then_task_keeps_b_open_and_the_room_occupied(): void
    {
        $a = $this->declare('BK-A', '2026-12-08', '2026-12-10');
        $b = $this->declare('BK-B', '2026-12-10', '2026-12-12');

        $this->assertSame('completed', $a->fresh()->status, 'Séjour précédent clos, jamais de refus.');
        $this->assertSame('2026-12-10', $a->fresh()->actual_check_out_date->toDateString());

        $this->runTask();

        $this->assertSame('active', $b->fresh()->status);
        $this->assertSame(['BK-B'], $this->openStaysInRoom());
    }

    public function test_c_two_rotations_the_same_day_in_the_same_room(): void
    {
        $a = $this->declare('BK-A', '2026-12-08', '2026-12-10');
        $this->runTask();
        $b = $this->declare('BK-B', '2026-12-10', '2026-12-10');
        $c = $this->declare('BK-C', '2026-12-10', '2026-12-11');
        $this->runTask();

        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame('2026-12-10', $b->fresh()->actual_check_out_date->toDateString());
        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame(['BK-C'], $this->openStaysInRoom());
    }

    public function test_new_fiche_on_a_room_still_occupied_closes_the_previous_stay_at_today_when_its_departure_is_ahead(): void
    {
        $a = $this->declare('BK-A', '2026-12-08', '2026-12-14');
        $this->declare('BK-B', '2026-12-10', '2026-12-12');

        $this->assertSame('2026-12-10', $a->fresh()->actual_check_out_date->toDateString());
        $this->assertSame(['BK-B'], $this->openStaysInRoom());
    }

    public function test_patch_extends_the_stay_and_is_audited(): void
    {
        $a = $this->declare('BK-EXT', '2026-12-08', '2026-12-10');

        $this->patchFiche($a, ['departure_date' => '2026-12-13'])
            ->assertOk()
            ->assertJsonPath('departure_date', '2026-12-13')
            ->assertJsonPath('stay_status', 'active');

        $this->runTask();
        $this->assertSame('active', $a->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'check_in.updated_by_partner', 'subject_id' => $a->id]);
    }

    public function test_patch_extending_a_stay_already_closed_by_the_task_reopens_it(): void
    {
        $a = $this->declare('BK-REOPEN', '2026-12-08', '2026-12-10');
        $this->runTask();

        $this->patchFiche($a, ['departure_date' => '2026-12-11'])
            ->assertOk()
            ->assertJsonPath('stay_status', 'active')
            ->assertJsonPath('checked_out_at', null);
    }

    public function test_patch_changes_room_and_frees_the_old_one(): void
    {
        $a = $this->declare('BK-MOVE', '2026-12-08', '2026-12-12');
        $suite = Room::factory()->for($this->fixture['hotel'])->create();

        $this->patchFiche($a, ['room_id' => $suite->id, 'room' => 'Suite'])
            ->assertOk()
            ->assertJsonPath('room_id', $suite->id);

        $this->assertSame([], $this->openStaysInRoom());
        $this->assertSame(['BK-MOVE'], $this->openStaysInRoom($suite));
        $this->assertSame('Suite', $a->fresh()->metadata['room_label']);
    }

    public function test_patch_refuses_a_departure_before_arrival_and_foreign_fiches(): void
    {
        $a = $this->declare('BK-BAD', '2026-12-08', '2026-12-12');

        $this->patchFiche($a, ['departure_date' => '2026-12-07'])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->patchFiche($a, [])->assertStatus(422);

        $native = CheckIn::factory()->for($this->fixture['hotel'])->create(['status' => 'active']);
        $this->patchFiche($native, ['departure_date' => '2026-12-20'])->assertStatus(404)->assertJsonPath('error.code', 'fiche_not_found');
    }
}
