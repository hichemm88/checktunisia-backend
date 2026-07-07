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
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
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
