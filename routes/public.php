<?php

use App\Http\Controllers\Whatsapp\FicheLinkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes publiques SANS le préfixe /api/v1
|--------------------------------------------------------------------------
|
| Ce fichier existe pour une seule raison : certaines URL sont publiées
| ailleurs que dans notre code et ne peuvent plus bouger. Les enfermer
| derrière /api/v1 les rendrait longues et, surtout, laisserait croire
| qu'elles suivent le versionnage de l'API — alors qu'elles doivent survivre
| aux versions.
|
| Le cas présent : le bouton « Consulter la fiche » des messages WhatsApp.
| Son URL de base est figée à l'approbation du modèle chez Meta ; la changer
| impose une nouvelle soumission et une nouvelle validation.
|
| N'ajouter ici que des routes qui relèvent réellement de ce critère.
|
*/

Route::get('f/{token}', FicheLinkController::class)
    ->where('token', '[A-Za-z0-9]{1,32}')
    ->middleware('throttle:fiche-link')
    ->name('fiche.link');
