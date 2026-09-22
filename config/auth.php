<?php

/**
 * This app has no session-based login (Sanctum API tokens only, no
 * Auth::routes()/web guard in use) — this file exists solely so
 * Illuminate\Support\Facades\Password can resolve a broker for
 * AuthController::forgotPassword()/resetPassword(), which otherwise throw
 * since Laravel's password broker always reads config('auth.*').
 */
return [
    'defaults' => [
        'guard'     => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // CRM de prospection interne — jeton porteur dédié, résolu par
        // App\Providers\ProspectionServiceProvider (Auth::viaRequest).
        // Volontairement PAS le guard Sanctum de production : voir le
        // commentaire de la migration create_prospection_access_tokens_table.
        'prospection' => [
            'driver'   => 'prospection-token',
            'provider' => 'prospection_users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],

        'prospection_users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\Prospection\ProspectionUser::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table'    => 'password_reset_tokens',
            // 48h, per the invite/set-password link expiry the platform owner asked for.
            'expire'   => 2880,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
