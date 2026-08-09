<?php

namespace Tests\Feature;

use App\Services\Observability\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Sonde externe du planificateur (« dead-man's switch »).
 *
 * Le principe, et toute sa valeur : l'alarme ne vit PAS ici. Le planificateur
 * se contente d'appeler une URL tierce à intervalle régulier ; c'est le
 * service tiers qui crie quand les appels CESSENT d'arriver. Une tâche
 * planifiée qui surveillerait le planificateur se tairait en même temps que
 * lui — son silence serait indiscernable de « tout va bien ».
 *
 * Les exigences que ces tests verrouillent :
 *  - inerte tant qu'aucune URL n'est configurée (aucun appel sortant) ;
 *  - jamais bloquant : une panne réseau ne fait pas échouer le planificateur,
 *    sinon la sonde casserait ce qu'elle observe ;
 *  - l'URL ne fuit nulle part : elle porte un jeton, la journaliser
 *    reviendrait à publier la capacité de faire taire l'alarme.
 */
class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(): SchedulerHeartbeat
    {
        return app(SchedulerHeartbeat::class);
    }

    public function test_it_stays_inert_when_no_probe_is_configured(): void
    {
        config(['monitoring.scheduler_ping_url' => null]);
        Http::fake();

        $this->assertFalse($this->heartbeat()->ping(), 'rien à signaler, rien à envoyer');
        Http::assertNothingSent();
    }

    public function test_it_pings_the_configured_probe(): void
    {
        config(['monitoring.scheduler_ping_url' => 'https://hc-ping.test/abcd-1234']);
        Http::fake(['hc-ping.test/*' => Http::response('OK', 200)]);

        $this->assertTrue($this->heartbeat()->ping());
        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.test/abcd-1234');
    }

    /**
     * Une sonde qui casse ce qu'elle observe est pire que pas de sonde : si le
     * tiers est injoignable, le planificateur doit continuer sa tournée.
     */
    public function test_an_unreachable_probe_never_breaks_the_scheduler(): void
    {
        config(['monitoring.scheduler_ping_url' => 'https://hc-ping.test/abcd-1234']);
        Http::fake(fn () => throw new ConnectionException('network unreachable'));

        $this->assertFalse($this->heartbeat()->ping(), 'échec signalé, jamais propagé');
    }

    public function test_a_failing_probe_never_leaks_its_url_to_the_logs(): void
    {
        config(['monitoring.scheduler_ping_url' => 'https://hc-ping.test/secret-token-9f2a']);
        Http::fake(fn () => throw new ConnectionException('boom'));

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->heartbeat()->ping();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('secret-token-9f2a', $line,
                'l\'URL de sonde est un jeton : la journaliser permettrait de faire taire l\'alarme');
        }
    }

    /** La commande existe pour tester le branchement à la main, avant de compter dessus. */
    public function test_the_command_reports_whether_the_probe_is_wired(): void
    {
        config(['monitoring.scheduler_ping_url' => null]);
        $this->artisan('qayed:scheduler-ping')
            ->expectsOutputToContain('SCHEDULER_PING_URL')
            ->assertSuccessful();

        config(['monitoring.scheduler_ping_url' => 'https://hc-ping.test/abcd-1234']);
        Http::fake(['hc-ping.test/*' => Http::response('OK', 200)]);
        $this->artisan('qayed:scheduler-ping')->assertSuccessful();
    }

    /**
     * Non-régression : le battement INTERNE (lu par le tableau de bord admin)
     * ne doit pas dépendre de la sonde externe. Ce sont deux témoins
     * indépendants, et c'est précisément ce qui fait leur utilité.
     */
    public function test_the_internal_heartbeat_does_not_depend_on_the_external_probe(): void
    {
        config(['monitoring.scheduler_ping_url' => 'https://hc-ping.test/abcd-1234']);
        Http::fake(fn () => throw new ConnectionException('boom'));

        cache()->forget('scheduler:last_run_at');
        $this->heartbeat()->beat();

        $this->assertNotNull(cache()->get('scheduler:last_run_at'),
            'le battement interne est écrit même quand la sonde externe est injoignable');
    }
}
