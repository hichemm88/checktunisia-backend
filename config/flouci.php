<?php

/**
 * Flouci Payment Gateway Configuration
 *
 * Obtain credentials from: https://dashboard.flouci.com
 *   → Settings → API Keys → App Token + App Secret
 *
 * Set in .env:
 *   FLOUCI_APP_TOKEN=...
 *   FLOUCI_APP_SECRET=...
 *   FLOUCI_SUCCESS_URL=https://your-frontend.com/hotel/payment/success
 *   FLOUCI_FAIL_URL=https://your-frontend.com/hotel/payment/failed
 */
return [

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    */
    'app_token'  => env('FLOUCI_APP_TOKEN', ''),
    'app_secret' => env('FLOUCI_APP_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    | Flouci production API. Do not change unless Flouci updates their URL.
    */
    'base_url' => env('FLOUCI_BASE_URL', 'https://developers.flouci.com/api'),

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    | After payment, Flouci redirects the user back to these URLs.
    | They receive ?payment_id=xxx as a query parameter.
    | The frontend then calls POST /hotel/payments/{id}/verify to confirm.
    */
    'success_url' => env(
        'FLOUCI_SUCCESS_URL',
        env('FRONTEND_URL', 'http://localhost:5173') . '/hotel/payment/success'
    ),
    'fail_url' => env(
        'FLOUCI_FAIL_URL',
        env('FRONTEND_URL', 'http://localhost:5173') . '/hotel/payment/failed'
    ),

    /*
    |--------------------------------------------------------------------------
    | Session Timeout (seconds)
    |--------------------------------------------------------------------------
    | How long the payment page stays active before expiring.
    | Default: 900 seconds (15 minutes).
    */
    'timeout_secs' => (int) env('FLOUCI_SESSION_TIMEOUT', 900),

];
