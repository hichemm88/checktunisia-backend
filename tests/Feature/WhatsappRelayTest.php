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

    public function test_fiche_includes_establishment_address_when_set(): void
    {
        $this->hotel->addresses()->create([
            'line1' => '11 rue du trésor',
            'city' => 'Tunis Medina',
            'governorate' => 'Tunis',
            'country_code' => 'TN',
            'is_primary' => true,
        ]);

        $checkIn = CheckIn::factory()->for($this->hotel)->draft()->withGuest('Sara', 'Trabelsi')->create([
            'created_by' => $this->receptionist->id,
        ]);

        $this->actingAs($this->receptionist)
            ->postJson("/api/v1/hotel/check-ins/{$checkIn->id}/complete")
            ->assertOk();

        $caption = WhatsappSendLog::first()->caption;
        // L'adresse apparaît juste sous l'en-tête, avant le nom du voyageur.
        $this->assertStringContainsString('Adresse : 11 rue du trésor - Tunis Medina, Tunis', $caption);
        $this->assertStringContainsString("DAR TEST\nAdresse :", $caption);
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

    public function test_admin_resend_all_unblocks_jobs_stuck_behind_backoff(): void
    {
        // Cas réel : après une panne réparée (session ré-appairée), les fiches
        // restaient « en attente » avec une prochaine tentative à +4 h. Le bouton
        // ne visait que les `failed` et la ligne « en attente » n'offre pas de
        // « Renvoyer » : plus rien dans l'admin ne pouvait les débloquer.
        $backoff = $this->pendingJob(['attempts' => 5, 'next_attempt_at' => now()->addHours(4)]);
        $failed = $this->pendingJob(['status' => 'failed', 'attempts' => 10]);
        $readyToGo = $this->pendingJob(['next_attempt_at' => now()->subMinute()]);
        $sent = $this->pendingJob(['status' => 'sent']);

        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk()
            ->assertJsonPath('data.queue.stuck', 2); // la repoussée + l'échouée

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/whatsapp/logs/resend-all')
            ->assertOk()
            ->assertJsonPath('data.requeued', 2);

        $unblocked = $backoff->fresh();
        $this->assertSame('pending', $unblocked->status);
        $this->assertTrue($unblocked->next_attempt_at->lessThanOrEqualTo(now()), 'la fiche doit être immédiatement dispatchable');
        $this->assertSame(0, $unblocked->attempts);
        $this->assertSame('pending', $failed->fresh()->status);

        // Ce qui n'était pas bloqué reste tel quel.
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('pending', $readyToGo->fresh()->status);
    }

    // ── Garde-fous anti-restriction Meta ─────────────────────────────────────

    public function test_hourly_cap_holds_the_queue_instead_of_flooding(): void
    {
        // Le 17/08/2026, Meta a restreint le numéro émetteur 6 h pour « envoi
        // groupé ». Un arriéré ne doit plus jamais partir d'un bloc.
        config(['whatsapp.max_per_hour' => 3]);
        WhatsappSessionState::current()->update(['status' => 'ready']);

        for ($i = 0; $i < 3; $i++) {
            $this->pendingJob(['status' => 'sent', 'sent_at' => now()->subMinutes(10 + $i)]);
        }
        $this->pendingJob();

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job', null);
    }

    public function test_hourly_cap_is_a_sliding_window_not_a_clock_hour(): void
    {
        // Un envoi sorti de la fenêtre libère une place aussitôt : sinon la file
        // repartirait par à-coups au top de chaque heure.
        config(['whatsapp.max_per_hour' => 2]);
        WhatsappSessionState::current()->update(['status' => 'ready']);

        $this->pendingJob(['status' => 'sent', 'sent_at' => now()->subMinutes(90)]);
        $this->pendingJob(['status' => 'sent', 'sent_at' => now()->subMinutes(5)]);
        $job = $this->pendingJob();

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->id);
    }

    public function test_freshly_paired_number_sends_slower(): void
    {
        // Un numéro neuf qui se met aussitôt à émettre est le cas d'école du
        // compte jetable — exactement le profil qui s'est fait restreindre.
        config([
            'whatsapp.warmup_hours' => 24,
            'whatsapp.warmup_min_interval_seconds' => 120,
            'whatsapp.min_interval_seconds' => 45,
        ]);
        WhatsappSessionState::current()->update(['status' => 'ready', 'paired_at' => now()->subHour()]);

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/control')
            ->assertOk()
            ->assertJsonPath('data.min_interval_seconds', 120);

        // Fenêtre écoulée : le numéro est rodé, la cadence normale revient.
        WhatsappSessionState::current()->update(['paired_at' => now()->subHours(48)]);

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/control')
            ->assertOk()
            ->assertJsonPath('data.min_interval_seconds', 45);
    }

    public function test_existing_installation_is_not_throttled_retroactively(): void
    {
        // `paired_at` nul = numéro antérieur à cette mécanique, donc déjà rodé.
        config(['whatsapp.min_interval_seconds' => 45, 'whatsapp.warmup_min_interval_seconds' => 120]);
        WhatsappSessionState::current()->update(['status' => 'ready', 'paired_at' => null]);

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/control')
            ->assertOk()
            ->assertJsonPath('data.min_interval_seconds', 45);
    }

    public function test_changing_the_sender_number_rearms_the_warmup(): void
    {
        $state = WhatsappSessionState::current();
        $state->update(['status' => 'ready', 'phone_number' => '21611111111', 'paired_at' => now()->subDays(30)]);

        // Reconnexion du MÊME numéro : rien ne repart de zéro.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', ['status' => 'ready', 'phone_number' => '21611111111'])
            ->assertOk();
        $this->assertTrue($state->fresh()->paired_at->lt(now()->subDays(29)), 'une reconnexion ne rebride pas');

        // Autre numéro : compte neuf pour Meta, montée en charge réarmée.
        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', ['status' => 'ready', 'phone_number' => '+216 93 116 000'])
            ->assertOk();

        $fresh = $state->fresh();
        $this->assertSame('21693116000', $fresh->phone_number, 'le numéro est normalisé en chiffres');
        $this->assertTrue($fresh->inWarmup());
    }

    public function test_repairing_after_a_revocation_rearms_warmup_even_on_the_same_number(): void
    {
        /*
         * Le 17/08/2026 : Meta restreint le numéro 6 h, puis révoque l'appareil
         * à la seconde où la restriction expire, 45 fiches en file. Se faire
         * débrancher par WhatsApp est un événement de réputation — ne réarmer
         * la montée en charge que sur CHANGEMENT de numéro laissait le cas le
         * plus dangereux repartir à pleine cadence.
         */
        $state = WhatsappSessionState::current();
        $state->update([
            'status' => 'logged_out',
            'phone_number' => '21693116000',
            'paired_at' => now()->subDays(30),
            'revoked_at' => now()->subHour(),
        ]);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', ['status' => 'ready', 'phone_number' => '21693116000'])
            ->assertOk();

        $fresh = $state->fresh();
        $this->assertTrue($fresh->inWarmup(), 'même numéro, mais réputation à refaire');
        $this->assertNull($fresh->revoked_at);
    }

    public function test_repairing_after_a_revocation_leaves_the_relay_paused(): void
    {
        // Vider un arriéré dans un canal qui vient de vous éjecter est le geste
        // à ne pas automatiser. Il faut un humain, qui a d'abord vérifié l'état
        // du compte sur le téléphone émetteur.
        $state = WhatsappSessionState::current();
        $state->update(['status' => 'logged_out', 'paused' => false, 'revoked_at' => now()->subHour()]);
        $this->pendingJob();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', ['status' => 'ready', 'phone_number' => '21693116000'])
            ->assertOk();

        $this->assertTrue($state->fresh()->paused);
        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job', null);
    }

    public function test_an_ordinary_reconnection_does_not_pause_the_relay(): void
    {
        // Une coupure réseau n'est pas une révocation : la reprise reste
        // automatique, sinon chaque hoquet réseau exigerait un clic.
        $state = WhatsappSessionState::current();
        $state->update(['status' => 'disconnected', 'paused' => false, 'revoked_at' => null]);
        $job = $this->pendingJob();

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', ['status' => 'ready', 'phone_number' => '21693116000'])
            ->assertOk();

        $this->assertFalse($state->fresh()->paused);
        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->id);
    }

    public function test_circuit_breaker_pauses_the_relay_and_frees_claimed_jobs(): void
    {
        // Le worker constate les refus en série ; le backend coupe DURABLEMENT.
        // Sa veille à lui vit en mémoire et ne survit pas au redémarrage d'un
        // conteneur — une restriction de compte, si.
        $state = WhatsappSessionState::current();
        $state->update(['status' => 'ready', 'paused' => false]);
        $claimed = $this->pendingJob(['claimed_at' => now()]);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/halt', ['reason' => '5 envois refusés d\'affilée'])
            ->assertOk()
            ->assertJsonPath('data.tripped', true);

        $this->assertTrue($state->fresh()->paused);
        $this->assertNull($claimed->fresh()->claimed_at, 'la fiche réclamée retourne en file, à sa place');

        // Relais coupé : plus rien ne sort, même session prête.
        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job', null);
    }

    public function test_circuit_breaker_does_not_realert_when_already_paused(): void
    {
        WhatsappSessionState::current()->update(['status' => 'ready', 'paused' => true]);

        $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/halt', ['reason' => 'refus en série'])
            ->assertOk()
            ->assertJsonPath('data.tripped', false);
    }

    public function test_admin_resume_lifts_the_circuit_breaker(): void
    {
        // La reprise reste un geste humain : rien ne rouvre le canal tout seul.
        WhatsappSessionState::current()->update(['status' => 'ready', 'paused' => true]);
        $job = $this->pendingJob();

        $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/whatsapp/resume')
            ->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/next')
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->id);
    }

    public function test_health_explains_why_a_throttled_queue_is_not_moving(): void
    {
        // Sans ça, « en attente » sous plafond est indiscernable d'une panne.
        config(['whatsapp.max_per_hour' => 2]);
        WhatsappSessionState::current()->update(['status' => 'ready']);
        $this->pendingJob(['status' => 'sent', 'sent_at' => now()->subMinutes(30)]);
        $this->pendingJob(['status' => 'sent', 'sent_at' => now()->subMinutes(20)]);

        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk()
            ->assertJsonPath('data.throttle.sending', false)
            ->assertJsonPath('data.throttle.sent_last_hour', 2)
            ->assertJsonPath('data.throttle.max_per_hour', 2)
            ->assertJsonPath('data.throttle.warmup', false);
    }
}
