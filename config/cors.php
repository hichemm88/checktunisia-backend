<?php

return [
    'paths'                    => ['api/*'],
    'allowed_methods'          => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins'          => array_filter([
        'https://checktunisia.vercel.app',
        'https://qayed.tn',
        'https://www.qayed.tn',
        // Local dev preview only — never allowed in production regardless of
        // what gets left in this file by mistake. Uses env() directly (not
        // app()->environment()) because config files load before the
        // container's 'env' binding exists — calling app()->environment()
        // here throws "Target class [env] does not exist" during boot.
        env('APP_ENV') !== 'production' ? 'http://localhost:4173' : null,
    ]),
    'allowed_origins_patterns' => [],
    'allowed_headers'          => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With', 'X-Property-Id'],
    'exposed_headers'          => [],
    'max_age'                  => 86400,
    'supports_credentials'     => false,
];
