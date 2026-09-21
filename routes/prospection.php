<?php

use App\Http\Controllers\Prospection\AuthController;
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
| Le mapping complet Établissements/Actions/Templates/Import/Export/
| Dashboard arrive en PR2 (§ étapes du prompt) ; ce fichier ne porte pour
| l'instant que l'authentification et la gestion des comptes membres.
*/

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:prospection')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::patch('users/{id}', [UserController::class, 'update']);
});
