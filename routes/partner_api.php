<?php

use App\Http\Controllers\PartnerApi\EstablishmentLinkExchangeController;
use App\Http\Controllers\PartnerApi\FicheSessionController;
use App\Http\Controllers\PartnerApi\PartnerEstablishmentController;
use App\Http\Controllers\PartnerApi\PartnerFicheController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API publique v1 (partenaires) — HORS /api/v1
|--------------------------------------------------------------------------
|
| Ce fichier vit délibérément hors du préfixe /api/v1 appliqué à routes/api.php
| (voir bootstrap/app.php, apiPrefix), sur le même principe que routes/public.php :
| c'est un contrat public tiers, versionné indépendamment de l'API interne, à
| l'adresse annoncée dans la doc développeurs (https://qayed.tn/v1/…).
|
| Authentification : clé API partenaire (Bearer qyd_live_…/qyd_test_…), jamais
| de session Sanctum. Enveloppe d'erreur dédiée {error:{code,message,doc_url}} —
| voir bootstrap/app.php withExceptions() et API-V1-DECISIONS.md (D3).
|
*/

// Spec OpenAPI 3.1 + exemples de vérification de signature — publics, sans
// authentification (doc développeurs, §7).
Route::get('v1/openapi.yaml', fn () => response()
    ->file(base_path('docs/api/openapi.yaml'), ['Content-Type' => 'application/yaml']));
Route::get('v1/examples/verify-webhook.js', fn () => response()
    ->file(base_path('docs/api/examples/verify-webhook.js'), ['Content-Type' => 'text/plain']));
Route::get('v1/examples/verify-webhook.php', fn () => response()
    ->file(base_path('docs/api/examples/verify-webhook.php'), ['Content-Type' => 'text/plain']));

Route::prefix('v1')
    ->middleware(['partner.key', 'partner.rate_limit'])
    ->group(function () {
        Route::post('establishment-links', [EstablishmentLinkExchangeController::class, 'store'])
            ->middleware('throttle:establishment-link-exchange');

        Route::get('establishments', [PartnerEstablishmentController::class, 'index']);

        Route::post('fiche-sessions', [FicheSessionController::class, 'store']);
        Route::get('fiche-sessions/{session_id}', [FicheSessionController::class, 'show']);

        Route::get('fiches/{fiche_id}', [PartnerFicheController::class, 'show']);
    });
