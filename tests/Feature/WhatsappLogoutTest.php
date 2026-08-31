<?php

namespace Tests\Feature;

use App\Models\WhatsappSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Distinction entre une coupure passagère et une révocation WhatsApp.
 *
 * Incident du 2026-08-08 : après un LOGOUT, le worker redémarrait, annonçait
 * « initializing », et le système restait dans cet état — indiscernable d'un
 * démarrage en cours. Aucune alerte ne disait qu'un ré-appairage humain était
 * devenu indispensable, et les fiches s'accumulaient en silence.
 */
class WhatsappLogoutTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,string> */
    private function workerHeaders(): array
    {
        return ['X-Whatsapp-Worker-Secret' => 'test-secret'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21612345678@c.us',
            'whatsapp.worker_secret' => 'test-secret',
        ]);

        Mail::fake();
    }

    private function report(string $status, ?string $reason = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->workerHeaders())
            ->postJson('/api/v1/internal/whatsapp/session', array_filter([
                'status' => $status,
                'reason' => $reason,
            ]));
    }

    public function test_a_logout_is_recorded_as_a_revocation_with_its_moment(): void
    {
        $this->report('ready')->assertOk();
        $this->report('logged_out', 'WhatsApp a révoqué l\'appareil lié (LOGOUT)')->assertOk();

        $state = WhatsappSessionState::current()->fresh();

        $this->assertSame(WhatsappSessionState::STATUS_LOGGED_OUT, $state->status);
        $this->assertNotNull($state->revoked_at, 'l\'instant de la révocation doit être retenu');
        $this->assertTrue($state->needsPairing());
        $this->assertFalse($state->canDispatch(), 'la file ne doit pas avancer sur une session révoquée');
    }

    /**
     * Le cœur de l'incident : le redémarrage qui SUIT la révocation ne doit pas
     * l'effacer. Sinon le système se réaffiche « en cours d'initialisation » et
     * plus personne ne sait qu'un QR est attendu.
     */
    public function test_a_worker_restart_does_not_erase_a_pending_revocation(): void
    {
        $this->report('ready')->assertOk();
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $revokedAt = WhatsappSessionState::current()->fresh()->revoked_at;

        // Le worker reboote (déploiement, crash, redémarrage) et annonce son
        // état de démarrage habituel.
        $this->report('initializing')->assertOk();

        $state = WhatsappSessionState::current()->fresh();

        $this->assertSame(WhatsappSessionState::STATUS_LOGGED_OUT, $state->status, 'la révocation doit tenir');
        $this->assertEquals($revokedAt, $state->revoked_at);
        $this->assertNotNull($state->heartbeat_at, 'le battement de cœur est tout de même enregistré');
    }

    public function test_a_successful_re_pairing_clears_the_revocation(): void
    {
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $this->assertNotNull(WhatsappSessionState::current()->fresh()->revoked_at);

        // Le QR est scanné : la session repart.
        $this->report('ready')->assertOk();

        $state = WhatsappSessionState::current()->fresh();

        $this->assertSame(WhatsappSessionState::STATUS_READY, $state->status);
        $this->assertNull($state->revoked_at, 'une session reprise n\'est plus révoquée');
        $this->assertTrue($state->canDispatch());
    }

    /**
     * Une coupure technique reste une coupure technique : elle ne doit pas
     * marquer la session comme révoquée, sinon le worker cesserait de restaurer
     * une archive parfaitement valide.
     */
    public function test_a_technical_disconnect_is_not_a_revocation(): void
    {
        $this->report('ready')->assertOk();
        $this->report('disconnected', 'NAVIGATION')->assertOk();

        $state = WhatsappSessionState::current()->fresh();

        $this->assertSame(WhatsappSessionState::STATUS_DISCONNECTED, $state->status);
        $this->assertNull($state->revoked_at);
        $this->assertFalse($state->needsPairing());

        // Et un redémarrage la ramène normalement en initialisation.
        $this->report('initializing')->assertOk();
        $this->assertSame(WhatsappSessionState::STATUS_INITIALIZING, WhatsappSessionState::current()->fresh()->status);
    }

    /**
     * Le worker interroge cette route au démarrage pour savoir si l'archive du
     * coffre vaut encore quelque chose. Sans `revoked_at`, il restaurait
     * indéfiniment des credentials morts.
     */
    public function test_the_vault_metadata_exposes_the_revocation_moment(): void
    {
        $this->report('logged_out', 'LOGOUT')->assertOk();

        $meta = $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/session-archive/meta')
            ->assertOk()
            ->json('data');

        $this->assertNotNull($meta['revoked_at']);
        $this->assertArrayHasKey('stored_at', $meta);
        // Jamais un octet de la session elle-même.
        $this->assertArrayNotHasKey('contents', $meta);
    }

    public function test_the_admin_health_endpoint_reports_the_revoked_session(): void
    {
        $this->report('logged_out', 'LOGOUT')->assertOk();

        // L'état de session a quitté la route publique : il décrit notre
        // infrastructure, pas un service rendu à l'appelant.
        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/whatsapp/health')
            ->assertOk()
            ->assertJsonPath('data.session', WhatsappSessionState::STATUS_LOGGED_OUT);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->report('inventé')->assertStatus(422);
    }

    // ── Idempotence des alertes ───────────────────────────────────────────────
    //
    // Deux mécanismes envoient l'alerte : la transition de statut (worker) et la
    // commande planifiée whatsapp:check-health (toutes les 10 min). Chacun avait
    // sa propre déduplication, et aucune ne portait sur l'ÉVÉNEMENT lui-même.

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->platformAdmin()->create();
    }

    /** Nombre d'emails d'alerte réellement partis. */
    private function sentAlerts(): int
    {
        $count = 0;
        Mail::assertSent(\App\Mail\SystemMail::class, function () use (&$count) {
            $count++;

            return true;
        });

        return $count;
    }

    public function test_one_revocation_produces_exactly_one_email_whoever_reports_it(): void
    {
        $this->admin();

        $this->report('ready')->assertOk();
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $this->assertSame(1, $this->sentAlerts(), 'le worker alerte une fois');

        // La commande planifiée passe et constate la même panne, avec son
        // propre libellé. Elle ne doit pas produire un second email.
        $state = WhatsappSessionState::current()->fresh();
        app(\App\Services\Whatsapp\WhatsappAlertService::class)
            ->sessionDown($state->status, 'La session n\'est plus opérationnelle depuis 20 min.', $state);

        $this->assertSame(1, $this->sentAlerts());
    }

    public function test_a_technical_disconnect_after_a_revocation_sends_no_second_email(): void
    {
        $this->admin();

        $this->report('ready')->assertOk();
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $this->assertSame(1, $this->sentAlerts());

        // Sans convergence des clés, celui-ci annoncerait « reconnexion
        // automatique, aucune action requise » juste après avoir réclamé un QR.
        $this->report('disconnected', 'NAVIGATION')->assertOk();

        $this->assertSame(1, $this->sentAlerts());
    }

    public function test_a_worker_restart_sends_no_new_email(): void
    {
        $this->admin();

        $this->report('ready')->assertOk();
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $this->assertSame(1, $this->sentAlerts());

        $this->report('initializing')->assertOk();
        $this->report('logged_out', 'Coffre antérieur à la révocation.')->assertOk();

        $this->assertSame(1, $this->sentAlerts(), 'un redémarrage ne réveille pas l\'alerte');
    }

    public function test_a_genuinely_new_revocation_does_alert_again(): void
    {
        $this->admin();

        $this->report('ready')->assertOk();
        $this->report('logged_out', 'LOGOUT')->assertOk();
        $this->assertSame(1, $this->sentAlerts());

        // Le QR est scanné : la session repart, la révocation est levée…
        $this->travel(2)->minutes();
        $this->report('ready')->assertOk();
        $this->assertNull(WhatsappSessionState::current()->fresh()->revoked_at);

        // …puis WhatsApp révoque de nouveau. C'est un ÉVÉNEMENT distinct :
        // l'alerte DOIT repartir, sinon une vraie panne passerait inaperçue.
        $this->travel(2)->minutes();
        $this->report('logged_out', 'LOGOUT')->assertOk();

        $this->assertSame(2, $this->sentAlerts());
    }

    public function test_a_healthy_session_never_alerts(): void
    {
        $this->admin();

        $this->report('initializing')->assertOk();
        $this->report('ready')->assertOk();

        Mail::assertNothingSent();
    }
}
