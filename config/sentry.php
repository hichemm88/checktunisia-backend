<?php

/*
|--------------------------------------------------------------------------
| Suivi des erreurs de production
|--------------------------------------------------------------------------
|
| Sans SENTRY_LARAVEL_DSN, le SDK est totalement inerte : rien n'est capturé,
| rien n'est envoyé. C'est l'état par défaut en local et en test.
|
| Ce produit héberge un registre d'identité de voyageurs. La règle est donc
| stricte : on envoie de quoi DIAGNOSTIQUER (où, quel tenant, quelle route),
| jamais de quoi IDENTIFIER une personne (nom, numéro de document, email,
| date de naissance, adresse).
|
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('SENTRY_RELEASE'),

    // false = Sentry n'attache NI le corps de requête, NI les cookies, NI l'IP,
    // NI l'utilisateur authentifié. C'est le réglage qui fait le gros du travail
    // de protection ici : le corps d'une requête de check-in contient le nom, la
    // date de naissance et le numéro de document du voyageur.
    'send_default_pii' => false,

    // Échantillonnage des traces de performance. 0 = désactivé : la performance
    // se mesure d'abord côté base (voir docs/ci-cd.md et les mesures DB-01),
    // et les traces multiplient le volume envoyé.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'breadcrumbs' => [
        'logs' => true,
        // Les requêtes SQL en fil d'Ariane exposeraient les valeurs liées —
        // donc les noms et numéros de documents. Désactivé.
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
    ],

    /*
    | Dernier filet avant l'envoi.
    |
    | Deux rôles : (1) retirer tout ce qui pourrait identifier un voyageur et
    | qui aurait échappé aux réglages ci-dessus ; (2) ne pas polluer le suivi
    | avec des exceptions qui ne sont pas des défauts (422, 404, 429…).
    |
    | Référence [classe, méthode] et NON une closure : var_export, utilisé par
    | « artisan config:cache », ne sait pas sérialiser une closure — le
    | conteneur de production ne démarrait plus (start.sh tourne avec set -e).
    | Voir App\Services\Observability\SentryScrubber.
    */
    'before_send' => [\App\Services\Observability\SentryScrubber::class, 'beforeSend'],

];
