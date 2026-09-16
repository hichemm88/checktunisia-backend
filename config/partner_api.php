<?php

/*
|--------------------------------------------------------------------------
| API publique v1 + widget embarqué (partenaires)
|--------------------------------------------------------------------------
|
| Voir API-V1-DECISIONS.md à la racine du dépôt pour les écarts assumés par
| rapport au brief d'origine.
*/

return [

    'jwt' => [
        // Secret DÉDIÉ, distinct de APP_KEY et de la config Sanctum : un widget
        // compromis ne doit jamais permettre de forger un token d'une autre nature.
        'secret' => env('WIDGET_JWT_SECRET'),
        'ttl_minutes' => (int) env('WIDGET_JWT_TTL_MINUTES', 15),
    ],

    // Durée de vie du token de session widget (opaque), émis au premier
    // bootstrap réussi et utilisé pour tous les appels suivants (scan,
    // add/remove guest, submit) — c'est lui, et non le JWT d'origine, qui
    // porte la saisie du réceptionniste.
    'widget_session' => [
        'ttl_minutes' => (int) env('WIDGET_SESSION_TTL_MINUTES', 20),
    ],

    'link_code' => [
        'ttl_hours' => (int) env('ESTABLISHMENT_LINK_CODE_TTL_HOURS', 24),
    ],

    'webhooks' => [
        // Backoff exponentiel, en minutes depuis le premier échec — au moins 5
        // tentatives sur 24h (§4).
        'retry_schedule_minutes' => [1, 5, 15, 60, 240, 1440],
        'max_age_minutes' => 1440,

        // Endpoint désactivé automatiquement après N échecs consécutifs
        // (visible et réactivable depuis l'admin).
        'auto_disable_after_failures' => (int) env('PARTNER_WEBHOOK_AUTO_DISABLE_FAILURES', 20),

        // Tolérance anti-rejeu sur la signature (Qayed-Signature: t=…,v1=…).
        'signature_tolerance_seconds' => (int) env('PARTNER_WEBHOOK_SIGNATURE_TOLERANCE_SECONDS', 300),

        'http_timeout_seconds' => (int) env('PARTNER_WEBHOOK_TIMEOUT_SECONDS', 5),
    ],
];
