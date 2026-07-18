<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MODULE PROVISOIRE — relais WhatsApp check-in (à retirer après homologation MI).
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Couvre les critères d'acceptation §8 :
 *  - un check-in complété enfile une fiche (propriété en tête) au destinataire ;
 *  - module désactivé → aucun effet de bord ;
 *  - API worker authentifiée par secret ; distribution FIFO seulement si prête
 *    et non en pause ; verdict sent/failed (backoff) ; pause/resume ; santé ;
 *    renvoi manuel.
 */
class WhatsappRelayTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $receptionist;

    private User $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
        $this->platformAdmin = User::factory()->platformAdmin()->create();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21612345678@c.us',
            'whatsapp.worker_secret' => 'test-secret',
        ]);
    }

    /** @return array<string,string> */
    private function workerHeaders(string $secret = 'test-secret'): array
    {
        return ['X-Whatsapp-Worker-Secret' => $secret];
    }

    private function pendingJob(array $overrides = []): WhatsappSendLog
    {
        return WhatsappSendLog::create(array_merge([
            'hotel_id' => $this->hotel->id,
            'recipient' => '21612345678@c.us',
            'caption' => 'x',
            'status' => 'pending',
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ], $overrides));
    }

    // ── Enfilage sur check-in complété (§8.1) ────────────────────────────────

    public function test_completing_checkin_enqueues_pending_row_with_property_header(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 1);

        $row = WhatsappSendLog::first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('21612345678@c.us', $row->recipient);
        $this->assertSame($this->hotel->id, $row->hotel_id);
        $this->assertStringContainsString('FICHE DE POLICE — DAR TEST', $row->caption);
        $this->assertStringContainsString('TRABELSI Sara', $row->caption);
    }

    // ── Voyageur ajouté APRÈS finalisation du séjour ─────────────────────────

    /** @return array<string,mixed> */
    private function guestPayload(string $first, string $last): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'date_of_birth' => '1990-01-01',
            'sex' => 'F',
            'nationality_code' => 'ITA',
            'document' => [
                'type' => 'passport',
                'document_number' => 'P'.strtoupper($last).'1',
                'issuing_country_code' => 'ITA',
            ],
        ];
    }

    public function test_guest_added_to_finalized_checkin_gets_its_own_fiche(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Dennis', 'Forosetti')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 1);

        // Voyageur ajouté APRÈS coup : sa fiche doit être enfilée elle aussi.
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload('Beatrice', 'Tani'))
            ->assertSuccessful();

        $this->assertDatabaseCount('whatsapp_send_log', 2);

        // Ciblage par voyageur : les deux fiches partagent le même queued_at,
        // un tri par date désignerait l'une ou l'autre au hasard.
        // Les noms de famille sont stockés en majuscules (findOrCreateGuest).
        $tani = Guest::where('last_name', 'TANI')->sole();
        $added = WhatsappSendLog::where('guest_id', $tani->id)->sole();

        $this->assertSame('pending', $added->status);
        $this->assertStringContainsString('TANI Beatrice', $added->caption);
        $this->assertStringContainsString('FICHE DE POLICE — DAR TEST', $added->caption);
    }

    public function test_guest_added_to_draft_checkin_is_enqueued_once_at_completion(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Dennis', 'Forosetti')->create([
            'created_by' => $this->receptionist->id,
        ]);

        // Ajout pendant le brouillon : rien ne part encore…
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/guests", $this->guestPayload('Beatrice', 'Tani'))
            ->assertSuccessful();

        $this->assertDatabaseCount('whatsapp_send_log', 0);

        // …et la finalisation enfile chacun une seule fois (pas de doublon).
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 2);
    }

    public function test_disabled_module_enqueues_nothing(): void
    {
        config(['whatsapp.enabled' => false]);

        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $this->assertDatabaseCount('whatsapp_send_log', 0);
    }

    // ── API interne worker (secret + FIFO + pause) ───────────────────────────

    public function test_worker_endpoints_require_shared_secret(): void
    {
        $this->getJson('/api/v1/internal/whatsapp/next')->assertUnauthorized();
        $this->getJson('/api/v1/internal/whatsapp/next', $this->workerHeaders('wrong'))->assertUnauthorized();
        $this->getJson('/api/v1/internal/whatsapp/control', $this->workerHeaders())->assertOk();
    }

    public function test_worker_gets_no_job_until_session_ready(): void
    {
        $this->pendingJob();

        // Session « initializing » par défaut → rien à envoyer.
        $this->getJson('/api/v1/internal/whatsapp/next', $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('data.job', null);

        WhatsappSessionState::current()->update(['status' => 'ready']);

        $this->getJson('/api/v1/internal/whatsapp/next', $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('data.job.recipient', '21612345678@c.us');
    }

    public function test_paused_module_returns_no_job(): void
    {
        $this->pendingJob();
        WhatsappSessionState::current()->update(['status' => 'ready', 'paused' => true]);

        $this->getJson('/api/v1/internal/whatsapp/next', $this->workerHeaders())
            ->assertOk()
            ->assertJsonPath('data.job', null);
    }

    public function test_worker_result_marks_sent_with_message_id(): void
    {
        $job = $this->pendingJob();

        $this->postJson("/api/v1/internal/whatsapp/jobs/{$job->id}/result", [
            'status' => 'sent',
            'message_id' => 'ABC123',
        ], $this->workerHeaders())->assertOk();

        $fresh = $job->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame('ABC123', $fresh->message_id_whatsapp);
        $this->assertNotNull($fresh->sent_at);
    }

    public function test_worker_result_failed_reschedules_with_backoff(): void
    {
        $job = $this->pendingJob(['attempts' => 1]);

        $this->postJson("/api/v1/internal/whatsapp/jobs/{$job->id}/result", [
            'status' => 'failed',
            'error' => 'boom',
        ], $this->workerHeaders())->assertOk();

        $fresh = $job->fresh();
        $this->assertSame('pending', $fresh->status);   // pas encore abandonné
        $this->assertSame('boom', $fresh->last_error);
        $this->assertTrue($fresh->next_attempt_at->isFuture());
    }

    public function test_worker_result_failed_gives_up_after_max_age(): void
    {
        $job = $this->pendingJob(['attempts' => 10, 'queued_at' => now()->subDays(2)]);

        $this->postJson("/api/v1/internal/whatsapp/jobs/{$job->id}/result", [
            'status' => 'failed',
            'error' => 'still failing',
        ], $this->workerHeaders())->assertOk();

        $this->assertSame('failed', $job->fresh()->status);
    }

    // ── Admin : santé, pause/reprise, renvoi ─────────────────────────────────

    public function test_health_is_public_and_reports_queue_counts(): void
    {
        $this->pendingJob();

        $this->getJson('/api/v1/health/whatsapp')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.queue.pending', 1);
    }

    public function test_admin_can_pause_and_resume(): void
    {
        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/whatsapp/pause')
            ->assertOk()
            ->assertJsonPath('data.paused', true);

        $this->assertTrue(WhatsappSessionState::current()->paused);

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/whatsapp/resume')
            ->assertOk()
            ->assertJsonPath('data.paused', false);
    }

    public function test_admin_resend_requeues_failed_job(): void
    {
        $job = $this->pendingJob(['status' => 'failed', 'attempts' => 10, 'last_error' => 'x']);

        $this->actingAs($this->platformAdmin)
            ->postJson("/api/v1/admin/whatsapp/logs/{$job->id}/resend")
            ->assertOk();

        $fresh = $job->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->last_error);
    }

    public function test_admin_routes_require_platform_admin(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/admin/whatsapp/logs')
            ->assertForbidden();
    }

    // ── §1.3 : jamais de fiche sans identité voyageur ────────────────────────

    public function test_nameless_guest_is_blocked_before_send_with_visible_cause(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('', '')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $row = WhatsappSendLog::first();
        $this->assertSame('cancelled', $row->status);
        $this->assertStringContainsString('Identité voyageur manquante', $row->last_error);
    }

    public function test_resend_of_nameless_fiche_stays_blocked(): void
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('', '')->create([
            'created_by' => $this->receptionist->id,
        ]);
        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();
        $job = WhatsappSendLog::first();

        $this->actingAs($this->platformAdmin)
            ->postJson("/api/v1/admin/whatsapp/logs/{$job->id}/resend")
            ->assertOk();

        $this->assertSame('cancelled', $job->fresh()->status);
    }

    // ── §1.3 : bouton « Relancer tout » ──────────────────────────────────────

    public function test_admin_resend_all_requeues_every_failed_job(): void
    {
        $failed1 = $this->pendingJob(['status' => 'failed', 'attempts' => 6, 'last_error' => 'timeout']);
        $failed2 = $this->pendingJob(['status' => 'failed', 'attempts' => 3, 'last_error' => 'timeout']);
        $sent    = $this->pendingJob(['status' => 'sent']);

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/whatsapp/logs/resend-all')
            ->assertOk()
            ->assertJsonPath('data.requeued', 2);

        $this->assertSame('pending', $failed1->fresh()->status);
        $this->assertSame('pending', $failed2->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
    }
}
