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

];
