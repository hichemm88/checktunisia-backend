<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Toggle optional platform features without deleting their code. Flip a flag
    | (here or via the matching env var) to re-enable a feature that has been
    | temporarily hidden.
    |
    */

    // Authority "expired / expiring document" alerts (dashboard KPI, alert card,
    // /authority/alerts endpoint & page). Temporarily hidden — set to true (or
    // FEATURE_EXPIRED_DOC_ALERTS=true) to bring it back. The frontend has a
    // mirrored flag in src/config/features.ts that must be flipped too.
    'expired_document_alerts' => (bool) env('FEATURE_EXPIRED_DOC_ALERTS', false),

    // Drainage de la file par le planificateur (toutes les minutes), faute de
    // worker dédié en production. Défaut true = comportement historique.
    //
    // Passer à false SUR LE SERVICE WEB dès qu'un service worker dédié tourne
    // (docker/worker.sh). Les deux peuvent coexister sans corruption — le
    // retrait d'un job est atomique — mais le drainage vole alors du CPU au
    // serveur web pour rien.
    //
    // Le drainage impose jusqu'à ~60 s de latence, ce qui est notable sur les
    // emails d'alerte de watchlist.
    'queue_drain_via_scheduler' => (bool) env('QUEUE_DRAIN_VIA_SCHEDULER', true),

];
