<?php

use App\Http\Controllers\Prospection\ActionController;
use App\Http\Controllers\Prospection\AuthController;
use App\Http\Controllers\Prospection\EstablishmentController;
use App\Http\Controllers\Prospection\ExportController;
use App\Http\Controllers\Prospection\ImportController;
use App\Http\Controllers\Prospection\MessageTemplateController;
use App\Http\Controllers\Prospection\ObjectionTagController;
use App\Http\Controllers\Prospection\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM de prospection — api/v1/prospection/*
|--------------------------------------------------------------------------
|
| Inclus depuis routes/api.php (Route::prefix('prospection')), donc sous le
| même apiPrefix 'api/v1' et les mêmes rendus d'exception ({data, errors}) —
| voir bootstrap/app.php. Outil interne : guard 'prospection' dédié (voir
| config/auth.php), jamais le guard Sanctum de production.
|
| Le CRUD complet des templates (§ Écran 5) arrive avec l'écran Templates ;
| seule la lecture est exposée ici (nécessaire au bouton WhatsApp).
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:prospection')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::patch('users/{id}', [UserController::class, 'update']);

    // AVANT establishments/{id} : sinon "today" serait avalé comme un {id}.
    Route::get('establishments/today', [EstablishmentController::class, 'today']);
    Route::get('establishments', [EstablishmentController::class, 'index']);
    Route::post('establishments', [EstablishmentController::class, 'store']);
    Route::get('establishments/{id}', [EstablishmentController::class, 'show']);
    Route::patch('establishments/{id}', [EstablishmentController::class, 'update']);
    Route::delete('establishments/{id}', [EstablishmentController::class, 'destroy']);

    Route::get('establishments/{establishmentId}/actions', [ActionController::class, 'index']);
    Route::post('establishments/{establishmentId}/actions', [ActionController::class, 'store']);

    Route::get('objection-tags', [ObjectionTagController::class, 'index']);
    Route::post('objection-tags', [ObjectionTagController::class, 'store']);
    Route::patch('objection-tags/{id}', [ObjectionTagController::class, 'update']);

    Route::get('message-templates', [MessageTemplateController::class, 'index']);

    Route::post('import/preview', [ImportController::class, 'preview']);
    Route::post('import/commit', [ImportController::class, 'commit']);

    Route::get('export', [ExportController::class, 'export']);
});
