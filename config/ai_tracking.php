<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Secret d'ingestion du tracking IA
    |--------------------------------------------------------------------------
    |
    | Secret partage entre la fonction serverless Vercel (qui appelle Anthropic
    | pour le scan CIN et le repli passeport) et la route interne
    | POST /internal/ai-usage. La meme valeur doit etre definie cote Vercel dans
    | INTERNAL_AI_TRACKING_SECRET. Tant qu'il est vide, la route interne rejette
    | tout (401) et aucun evenement n'est enregistre.
    |
    */
    'secret' => env('INTERNAL_AI_TRACKING_SECRET', ''),
];
