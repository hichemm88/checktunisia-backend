<?php

namespace Tests\Feature;

use App\Jobs\ExportPoliceFichesJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fiabilité de la file et visibilité des échecs (INF-03).
 *
 * La file porte l'export des fiches de police et les emails d'alerte de
 * watchlist. Un job perdu silencieusement, ici, c'est un manager qui attend un
 * document légal qui n'arrivera jamais.
 */
class QueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    // ── Politique de reprise portée par le job ───────────────────────────────

    public function test_export_job_carries_its_own_retry_policy(): void
    {
        $job = new ExportPoliceFichesJob('hotel-id', '2026-08-01', '2026-08-07', 'manager@example.test');

        // La politique ne doit PAS dépendre du « --tries=3 » de la ligne de
        // commande : un worker lancé autrement (service dédié, exécution
        // manuelle) ferait sinon échouer le job à la première erreur SMTP.
        $this->assertSame(3, $job->tries, 'Le job doit porter son nombre de tentatives.');
        $this->assertSame([10, 60, 300], $job->backoff, 'Attente croissante entre tentatives.');
        $this->assertGreaterThan(60, $job->timeout, 'La génération PDF d\'une longue plage dépasse le défaut de 60 s.');
    }

    public function test_export_job_declares_a_failed_handler(): void
    {
        // Sans failed(), un abandon définitif ne laisse qu'une ligne dans une
        // table que personne ne consulte.
        $this->assertTrue(
            method_exists(ExportPoliceFichesJob::class, 'failed'),
            'Le job doit tracer son abandon définitif.'
        );
    }

    // ── Bascule du drainage ──────────────────────────────────────────────────

    public function test_scheduler_drain_is_enabled_by_default(): void
    {
        // Défaut = comportement historique : rien ne change en production tant
        // que la variable n'est pas posée.
        $this->assertTrue(config('features.queue_drain_via_scheduler'));
    }

    public function test_scheduler_drain_can_be_disabled_for_a_dedicated_worker(): void
    {
        config(['features.queue_drain_via_scheduler' => false]);

        $this->assertFalse(config('features.queue_drain_via_scheduler'));
    }

    // ── Dead-letter ──────────────────────────────────────────────────────────

    public function test_failed_jobs_endpoint_requires_platform_admin(): void
    {
        $this->getJson('/api/v1/admin/health/failed-jobs')->assertUnauthorized();
    }

    public function test_failed_jobs_endpoint_lists_abandoned_jobs(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        DB::table('failed_jobs')->insert([
            'uuid'       => 'test-uuid-1',
            'connection' => 'redis',
            'queue'      => 'default',
            'payload'    => json_encode([
                'displayName' => 'App\\Jobs\\ExportPoliceFichesJob',
                'data'        => ['email' => 'manager@example.test'],
            ]),
            'exception'  => "RuntimeException: SMTP indisponible\n#0 /var/www/html/app/Jobs/Export.php(120)\n#1 ...",
            'failed_at'  => now(),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/health/failed-jobs')
            ->assertOk();

        $job = $response->json('data.0');

        $this->assertSame('App\\Jobs\\ExportPoliceFichesJob', $job['job']);
        $this->assertSame('RuntimeException: SMTP indisponible', $job['error'], 'Seule la première ligne de la trace doit remonter.');
    }

    public function test_failed_jobs_endpoint_never_leaks_the_serialized_payload(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        DB::table('failed_jobs')->insert([
            'uuid'       => 'test-uuid-2',
            'connection' => 'redis',
            'queue'      => 'default',
            // La charge utile réelle contient l'email du destinataire et
            // l'identifiant de l'établissement.
            'payload'    => json_encode([
                'displayName' => 'App\\Jobs\\ExportPoliceFichesJob',
                'data'        => ['email' => 'confidentiel@hotel.test', 'hotelId' => 'secret-hotel-id'],
            ]),
            'exception'  => 'RuntimeException: erreur',
            'failed_at'  => now(),
        ]);

        $body = $this->actingAs($admin)
            ->getJson('/api/v1/admin/health/failed-jobs')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('confidentiel@hotel.test', $body, 'Une adresse email a fuité.');
        $this->assertStringNotContainsString('secret-hotel-id', $body);
    }

    public function test_retrying_an_unknown_job_returns_404(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/health/failed-jobs/inconnu/retry')
            ->assertNotFound();
    }
}
