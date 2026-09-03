<?php

/*
|--------------------------------------------------------------------------
| Files d'attente
|--------------------------------------------------------------------------
|
| Copie publiée de la configuration du framework, pour une seule raison :
| `retry_after`.
|
| ── L'invariant, et ce qu'il coûte quand il est faux ──────────────────────
|
| `retry_after` dit au bout de combien de secondes la file considère qu'un job
| est PERDU et le redonne à un autre worker. Il doit donc être STRICTEMENT
| SUPÉRIEUR au `$timeout` du job le plus long. Sinon la file redonne un job
| qui est encore en train de s'exécuter : les deux exemplaires tournent
| ensemble, et le travail est fait deux fois.
|
| Le défaut du framework est 90 s. `ExportPoliceFichesJob` déclare
| `$timeout = 300`. Un export d'un mois de fiches dépassant 90 s repartait donc
| une seconde fois pendant que le premier travaillait encore — deux PDF
| générés, et surtout DEUX emails porteurs de fiches de police (identités,
| numéros de documents) envoyés au gérant.
|
| Rien ne le signalait : les deux exécutions réussissent, chacune de son côté.
|
| ── Pourquoi une valeur en dur plutôt qu'une variable d'environnement ─────
|
| Le framework lit `REDIS_QUEUE_RETRY_AFTER`, et laisser la correction dépendre
| d'une variable à poser sur le serveur ferait exactement ce qu'on cherche à
| éviter : une protection qui disparaît en silence si personne ne la pose. Le
| défaut est donc sûr par lui-même, et la variable reste disponible pour
| l'ajuster à la hausse.
|
| `QueueRetryAfterTest` vérifie l'invariant contre le `$timeout` réel de CHAQUE
| job : ajouter demain un job plus lent que cette valeur fera échouer la suite.
|
*/

/** Marge au-dessus du job le plus long. Voir QueueRetryAfterTest. */
$retryAfter = (int) env('QUEUE_RETRY_AFTER', 600);

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', $retryAfter),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', $retryAfter),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', $retryAfter),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
