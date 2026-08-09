<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\User;
use App\Models\WhatsappSessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le worker se recycle : ce n'est pas une perte de session.
 *
 * Incident du 2026-08-09 (22:03). Le worker Node redémarre son conteneur dès
 * trois échecs d'envoi consécutifs, quelle qu'en soit la cause — y compris une
 * photo que le backend ne rend pas. Il annonçait ce recyclage en
 * « disconnected », si bien que chaque tour de la boucle « échec → redémarrage
 * → mêmes fiches → échec » expédiait aux administrateurs un email
 * « session temporairement déconnectée » décrivant une panne qui n'existait
 * pas, pendant que les fiches, elles, s'accumulaient pour de bon.
 *
 * Deux propriétés sont vérifiées ici :
 *  • un recyclage volontaire (annoncé « initializing ») n'alerte personne ;
 *  • une session qui clignote n'alerte au plus qu'une fois par heure — sans
 *    jamais bâillonner la seule alerte qui réclame un geste humain, le
 *    ré-appairage.
 */
class WhatsappSelfRestartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'whatsapp.enabled' => true,
            'whatsapp.recipient' => '21612345678@c.us',
            'whatsapp.worker_secret' => 'test-secret',
        ]);

        Mail::fake();
        User::factory()->platformAdmin()->create();
    }

    private function report(string $status, ?string $reason = null): TestResponse
    {
        return $this->withHeaders(['X-Whatsapp-Worker-Secret' => 'test-secret'])
            ->postJson('/api/v1/internal/whatsapp/session', array_filter([
                'status' => $status,
                'reason' => $reason,
            ]));
    }

    /** Nombre d'emails d'alerte réellement partis. */
    private function sentAlerts(): int
    {
        $count = 0;
        Mail::assertSent(SystemMail::class, function () use (&$count) {
            $count++;

            return true;
        });

        return $count;
    }

    public function test_a_worker_recycling_its_browser_alerts_nobody(): void
    {
        $this->report('ready')->assertOk();

        // Ce que le worker annonce désormais quand il se recycle lui-même :
        // il repart, la session est intacte sur le volume, rien à signaler.
        $this->report('initializing', 'Recyclage technique du worker — 3 échecs d\'envoi consécutifs')->assertOk();
        $this->report('ready')->assertOk();

        Mail::assertNothingSent();
    }

    /** Le recyclage gèle bien la distribution le temps du redémarrage. */
    public function test_a_recycling_worker_stops_receiving_jobs(): void
    {
        $this->report('ready')->assertOk();
        $this->assertTrue(WhatsappSessionState::current()->fresh()->canDispatch());

        $this->report('initializing', 'Recyclage technique du worker')->assertOk();

        $this->assertFalse(
            WhatsappSessionState::current()->fresh()->canDispatch(),
            'un worker qui s\'apprête à disparaître ne doit pas se voir confier de fiche',
        );
    }

    public function test_a_flapping_session_alerts_at_most_once_an_hour(): void
    {
        $this->report('ready')->assertOk();
        $this->report('disconnected', 'NAVIGATION')->assertOk();
        $this->assertSame(1, $this->sentAlerts(), 'la première coupure est annoncée');

        // Elle revient, retombe : événement distinct (last_ready_at neuf), donc
        // clé de déduplication neuve. Sans plancher, c'était un email de plus.
        $this->travel(2)->minutes();
        $this->report('ready')->assertOk();
        $this->travel(2)->minutes();
        $this->report('disconnected', 'NAVIGATION')->assertOk();

        $this->assertSame(1, $this->sentAlerts(), 'la rafale est retenue');
    }

    public function test_an_outage_an_hour_later_is_announced_again(): void
    {
        $this->report('ready')->assertOk();
        $this->report('disconnected', 'NAVIGATION')->assertOk();
        $this->assertSame(1, $this->sentAlerts());

        $this->travel(70)->minutes();
        $this->report('ready')->assertOk();
        $this->travel(2)->minutes();
        $this->report('disconnected', 'CONFLICT')->assertOk();

        $this->assertSame(2, $this->sentAlerts(), 'une panne nouvelle, une heure plus tard, doit se dire');
    }

    /**
     * Le plancher ne doit JAMAIS retenir la seule alerte qui réclame un geste :
     * sans QR scanné, plus rien ne repart.
     */
    public function test_a_revocation_passes_through_the_hourly_floor(): void
    {
        $this->report('ready')->assertOk();
        $this->report('disconnected', 'NAVIGATION')->assertOk();
        $this->assertSame(1, $this->sentAlerts());

        $this->travel(1)->minutes();
        $this->report('logged_out', 'WhatsApp a révoqué l\'appareil lié (LOGOUT)')->assertOk();

        $this->assertSame(2, $this->sentAlerts(), 'un ré-appairage se dit sans attendre');
        $this->assertTrue(WhatsappSessionState::current()->fresh()->needsPairing());
    }

    /**
     * La mise en veille du worker (budget de redémarrages épuisé) est un vrai
     * problème non résolu : elle s'annonce en « disconnected » et doit alerter.
     */
    public function test_a_worker_giving_up_on_restarting_does_alert(): void
    {
        $this->report('ready')->assertOk();
        $this->report('disconnected', 'Envois suspendus 30 min après échecs répétés — budget de redémarrages épuisé')->assertOk();

        $this->assertSame(1, $this->sentAlerts());
        $this->assertFalse(WhatsappSessionState::current()->fresh()->canDispatch());
    }
}
