<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\Event;
use Sentry\EventHint;
use Tests\TestCase;

/**
 * Suivi d'erreurs et métriques de santé.
 *
 * L'enjeu principal ici n'est pas « Sentry fonctionne » mais « Sentry ne fait
 * pas fuiter le registre » : le corps d'une requête de check-in contient nom,
 * date de naissance et numéro de document d'un voyageur, et la query string
 * d'une recherche autorité contient les critères d'enquête.
 */
class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    // ── Protection des données personnelles ──────────────────────────────────

    public function test_sentry_never_sends_request_bodies_or_cookies(): void
    {
        $this->assertFalse(
            config('sentry.send_default_pii'),
            'send_default_pii doit rester false : sinon Sentry joint le corps de requête, '
            .'les cookies et l\'IP — donc les données du voyageur en cours de saisie.'
        );
    }

    public function test_sentry_does_not_record_sql_queries_as_breadcrumbs(): void
    {
        // Les fils d'Ariane SQL embarquent les valeurs liées : noms et numéros
        // de documents partiraient à chaque erreur.
        $this->assertFalse(config('sentry.breadcrumbs.sql_queries'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
    }

    public function test_before_send_strips_body_cookies_and_query_string(): void
    {
        $beforeSend = config('sentry.before_send');
        $this->assertIsCallable($beforeSend);

        $event = Event::createEvent();
        $event->setRequest([
            'url'          => 'https://api.qayed.tn/api/v1/authority/search?last_name=Mathlouthi&document_number=12345678',
            'query_string' => 'last_name=Mathlouthi&document_number=12345678',
            'data'         => ['first_name' => 'Mohamed', 'document_number' => '12345678'],
            'cookies'      => ['session' => 'abc'],
            'env'          => ['SECRET' => 'x'],
        ]);

        $result = $beforeSend($event, new EventHint());

        $this->assertNotNull($result);
        $request = $result->getRequest();

        $this->assertArrayNotHasKey('data', $request, 'Le corps de requête ne doit jamais partir.');
        $this->assertArrayNotHasKey('cookies', $request);
        $this->assertArrayNotHasKey('env', $request);
        $this->assertArrayNotHasKey('query_string', $request, 'Les critères de recherche autorité ne doivent jamais partir.');

        $this->assertStringNotContainsString('Mathlouthi', json_encode($request), 'Un nom de voyageur a fuité.');
        $this->assertStringNotContainsString('12345678', json_encode($request), 'Un numéro de document a fuité.');
        $this->assertSame('https://api.qayed.tn/api/v1/authority/search', $request['url']);
    }

    public function test_expected_exceptions_are_not_reported_as_incidents(): void
    {
        $beforeSend = config('sentry.before_send');

        // Une validation refusée ou une limite atteinte, c'est le système qui
        // fonctionne. Les remonter noierait les vrais défauts.
        $ignored = [
            \Illuminate\Validation\ValidationException::withMessages(['x' => 'y']),
            new \Illuminate\Auth\AuthenticationException(),
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(),
        ];

        foreach ($ignored as $exception) {
            $hint = new EventHint();
            $hint->exception = $exception;

            $this->assertNull(
                $beforeSend(Event::createEvent(), $hint),
                get_class($exception).' ne doit pas être remontée comme incident.'
            );
        }
    }

    public function test_genuine_errors_are_still_reported(): void
    {
        $beforeSend = config('sentry.before_send');

        $hint = new EventHint();
        $hint->exception = new \RuntimeException('quelque chose a vraiment cassé');

        $this->assertNotNull(
            $beforeSend(Event::createEvent(), $hint),
            'Un défaut réel doit bien être remonté.'
        );
    }

    public function test_sentry_is_inert_without_a_dsn(): void
    {
        // État par défaut en local, en test et tant que la variable n'est pas
        // posée en production : aucun envoi, aucune dépendance réseau.
        $this->assertNull(config('sentry.dsn'));
    }

    public function test_configuration_is_serializable_for_config_cache(): void
    {
        // Défaut bloquant constaté le 2026-08-07 : « before_send » était une
        // CLOSURE. var_export, utilisé par « artisan config:cache », ne sait
        // pas la sérialiser — et docker/start.sh tourne avec set -e, donc le
        // conteneur de production ne démarrait plus du tout.
        $inspected = 0;

        foreach (['sentry', 'backup', 'filesystems', 'whatsapp', 'features'] as $file) {
            $this->assertConfigSerializable(config($file), $file, $inspected);
        }

        // Garantit que la boucle a réellement parcouru quelque chose : sans
        // cela, un fichier de config renommé rendrait ce test vert à vide.
        $this->assertGreaterThan(20, $inspected, 'Trop peu de clés inspectées — les fichiers de config sont-ils bien chargés ?');
    }

    private function assertConfigSerializable(mixed $value, string $path, int &$inspected): void
    {
        $inspected++;

        if ($value instanceof \Closure) {
            $this->fail("config/{$path} contient une closure : « artisan config:cache » échouera et le conteneur ne démarrera pas.");
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertConfigSerializable($child, $path.'.'.$key, $inspected);
            }
        }
    }

    // ── Endpoint de santé ────────────────────────────────────────────────────

    public function test_health_endpoint_requires_platform_admin(): void
    {
        // L'anonyme d'abord : actingAs persiste sur les requêtes suivantes du
        // même test, l'ordre inverse testerait donc le mauvais cas.
        $this->getJson('/api/v1/admin/health')->assertUnauthorized();

        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $staff = User::factory()->hotelAdmin($hotel)->create();

        $this->actingAs($staff)->getJson('/api/v1/admin/health')->assertForbidden();
    }

    public function test_health_endpoint_reports_operational_metrics(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/health')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'database'  => ['reachable', 'latency_ms'],
                    'queue'     => ['driver', 'pending', 'failed_total'],
                    'scheduler' => ['last_run_at', 'stale'],
                    'whatsapp'  => ['pending', 'failed', 'sent'],
                    'checked_at',
                ],
            ]);

        $this->assertTrue($response->json('data.database.reachable'));
        // failed_jobs existe (migration 2026_07_26) : -1 signalerait une table absente.
        $this->assertGreaterThanOrEqual(0, $response->json('data.queue.failed_total'));
    }

    public function test_health_endpoint_exposes_no_personal_data(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $body = $this->actingAs($admin)->getJson('/api/v1/admin/health')->getContent();

        // Le corps ne doit contenir que des compteurs et des horodatages.
        $this->assertStringNotContainsString($admin->email, $body);
        $this->assertStringNotContainsString($admin->last_name, $body);
    }
}
