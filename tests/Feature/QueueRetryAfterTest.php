<?php

namespace Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

/**
 * `retry_after` doit dépasser le `$timeout` du job le plus long.
 *
 * ── L'invariant, et ce qu'il coûte quand il est faux ────────────────────
 *
 * `retry_after` dit au bout de combien de secondes la file considère qu'un job
 * est PERDU et le redonne à un autre worker. S'il est inférieur au `$timeout`
 * d'un job, la file redonne un job **encore en cours d'exécution** : les deux
 * exemplaires tournent ensemble et le travail est fait deux fois.
 *
 * C'était le cas : défaut du framework à 90 s (aucun `config/queue.php` n'était
 * publié), contre `ExportPoliceFichesJob::$timeout = 300`. Un export d'un mois
 * de fiches dépassant 90 s repartait pendant que le premier travaillait encore
 * — deux PDF générés, et surtout **deux emails porteurs de fiches de police**
 * envoyés au gérant.
 *
 * Rien ne le signalait : les deux exécutions réussissent, chacune de son côté.
 * C'est un doublon silencieux sur un envoi de données personnelles.
 *
 * ── Pourquoi ce test parcourt les classes ───────────────────────────────
 *
 * Vérifier « retry_after == 600 » ne protégerait de rien : la valeur juste
 * dépend des jobs, et c'est un job ajouté demain qui cassera l'invariant. Le
 * test lit donc le `$timeout` RÉEL de chaque job du projet. Un job plus lent
 * que `retry_after` fait échouer la suite, sans que personne ait eu à y penser.
 */
class QueueRetryAfterTest extends TestCase
{
    /**
     * `$timeout` déclaré par chaque job, indexé par classe.
     *
     * @return array<string,int>
     */
    private function jobTimeouts(): array
    {
        $timeouts = [];

        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            $properties = (new \ReflectionClass($class))->getDefaultProperties();
            // Un job sans `$timeout` hérite de celui du worker : il ne peut pas
            // dépasser `retry_after` de son propre fait.
            $timeouts[$class] = (int) ($properties['timeout'] ?? 0);
        }

        return $timeouts;
    }

    public function test_the_project_actually_has_queued_jobs_to_check(): void
    {
        // Sans cette garde, une erreur de chemin rendrait le test ci-dessous
        // vert sur un tableau vide — le pire des faux négatifs.
        $this->assertNotEmpty($this->jobTimeouts(), 'aucun job trouvé : le test ne vérifie rien');
    }

    public function test_no_job_can_outlive_the_retry_window(): void
    {
        $violations = [];

        foreach (['redis', 'database', 'beanstalkd'] as $connection) {
            $retryAfter = (int) config("queue.connections.$connection.retry_after");

            $this->assertGreaterThan(0, $retryAfter, "retry_after manquant sur « $connection »");

            foreach ($this->jobTimeouts() as $class => $timeout) {
                if ($timeout >= $retryAfter) {
                    $violations[] = sprintf(
                        '%s : $timeout=%d >= retry_after=%d (%s)',
                        class_basename($class),
                        $timeout,
                        $retryAfter,
                        $connection,
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Ces jobs peuvent être relancés pendant qu'ils tournent encore :\n  "
            .implode("\n  ", $violations),
        );
    }

    public function test_the_export_job_keeps_a_bounded_retry_budget(): void
    {
        // Le job qui porte des données personnelles doit rester borné : un
        // budget de tentatives infini transformerait un échec permanent en
        // envois répétés.
        $properties = (new \ReflectionClass(\App\Jobs\ExportPoliceFichesJob::class))->getDefaultProperties();

        $this->assertIsInt($properties['tries'] ?? null);
        $this->assertGreaterThan(0, $properties['tries']);
        $this->assertLessThanOrEqual(5, $properties['tries'], 'budget de tentatives trop large pour un envoi de fiches');
    }
}
