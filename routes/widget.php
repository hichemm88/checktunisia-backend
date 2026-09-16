<?php

use App\Http\Controllers\Widget\WidgetBootstrapController;
use App\Http\Controllers\Widget\WidgetGuestController;
use App\Http\Controllers\Widget\WidgetScanController;
use App\Http\Controllers\Widget\WidgetShellController;
use App\Http\Controllers\Widget\WidgetSubmitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Widget embarqué (§3) — HORS /api/v1, même principe que routes/partner_api.php
|--------------------------------------------------------------------------
|
| /widget/fiche       : page HTML (CSP frame-ancestors variable par partenaire).
| /widget/v1/bootstrap: authentifié par le JWT (usage unique, voir WidgetSessionService).
| Le reste           : authentifié par le token de session widget opaque
|                       émis au bootstrap (jamais le JWT d'origine).
|
*/

Route::get('widget/fiche', [WidgetShellController::class, 'show']);

Route::prefix('widget/v1')->middleware('throttle:widget-session')->group(function () {
    // L'origine ET la CSP de cette route sont posées dans le contrôleur
    // lui-même (WidgetBootstrapController::assertOriginAllowed) : le
    // partenaire n'est connu qu'après décodage du JWT porté par ?token=,
    // donc avant que le middleware générique puisse le résoudre.
    Route::get('bootstrap', [WidgetBootstrapController::class, 'show']);

    Route::middleware(['widget.session', 'widget.frame_ancestors'])->group(function () {
        Route::post('guests', [WidgetGuestController::class, 'store']);
        Route::delete('guests/{guestId}', [WidgetGuestController::class, 'destroy']);
        Route::post('scan', [WidgetScanController::class, 'store']);
        Route::get('scan/{scanId}/status', [WidgetScanController::class, 'status']);
        Route::post('submit', [WidgetSubmitController::class, 'store']);
    });
});
