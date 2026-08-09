<?php

return [
    'paths'                    => ['api/*'],
    'allowed_methods'          => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    // Socle du dépôt + ajouts éventuels de CORS_ALLOWED_ORIGINS. La variable
    // ne peut qu'AJOUTER : la lire comme source unique aurait coupé
    // www.qayed.tn, qu'elle ne mentionne pas. Règles et refus dans
    // App\Support\CorsOrigins, testés dans tests/Unit/CorsOriginsTest.
    //
    // `env()` directement (et non app()->environment()) : les fichiers de
    // configuration sont chargés avant que le conteneur ne lie 'env', et
    // app()->environment() lèverait « Target class [env] does not exist ».
    'allowed_origins'          => \App\Support\CorsOrigins::resolve(
        env('CORS_ALLOWED_ORIGINS'),
        env('APP_ENV'),
    ),
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Property-Id'],
    'exposed_headers'          => [],
    'max_age'                  => 86400,
    'supports_credentials'     => false,
];
