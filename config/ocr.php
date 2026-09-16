<?php

return [
    'driver'      => env('OCR_DRIVER', 'mock'),
    'service_url' => env('OCR_SERVICE_URL'),
    'service_key' => env('OCR_SERVICE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Lecture (Claude vision) des scans pris dans le widget embarqué
    |--------------------------------------------------------------------------
    |
    | Chemin séparé de `driver` ci-dessus : le flux natif (app Qayed) a déjà sa
    | lecture côté client via /api/scan/cin (hors de ce repo) et n'a besoin de
    | ce pilote backend que pour conserver l'image — l'activer globalement y
    | déclencherait un second appel Claude redondant, facturé en double, à
    | chaque scan natif. Le widget, lui, n'a ni ce endpoint (jeton de session
    | opaque, pas de Bearer Sanctum) ni de dépendance client lourde (bundle
    | volontairement séparé et léger) : c'est le SEUL consommateur de ce bloc.
    */
    'widget_vision' => [
        'enabled' => (bool) env('WIDGET_SCAN_AI', true),

        'api_key' => env('ANTHROPIC_API_KEY'),

        'model' => env('WIDGET_SCAN_MODEL', 'claude-opus-5'),
    ],
];
