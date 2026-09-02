<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Adresse publique du frontend
    |--------------------------------------------------------------------------
    |
    | Base de toutes les URL que Qayed publie hors de l'API : liens de première
    | connexion et de réinitialisation dans les emails, redirection du bouton
    | « Consulter la fiche » des messages WhatsApp.
    |
    | Cette clé existe pour une raison précise, et elle n'est pas cosmétique.
    | `docker/start.sh` exécute `php artisan config:cache` au démarrage. À
    | partir de là, Laravel NE LIT PLUS le fichier .env : `env()` appelé depuis
    | le code APPLICATIF rend sa valeur par défaut, toujours, quelle que soit la
    | variable réellement posée sur le serveur.
    |
    | Deux appels vivaient dans le code applicatif — `SystemMailer::frontendUrl()`
    | et `FicheLinkController` — et retombaient donc silencieusement sur
    | « https://qayed.tn » en production. Le repli étant plausible, la panne ne
    | se voyait pas. Elle se serait vue le jour d'un changement de domaine, ou
    | sur un environnement de recette, sous la forme de liens d'activation de
    | compte pointant vers le mauvais hôte — c'est-à-dire d'utilisateurs
    | incapables d'ouvrir leur compte, et aucun message d'erreur nulle part
    | pour le dire.
    |
    | Lu ICI, dans config/, l'appel est évalué AVANT la mise en cache et la
    | valeur est figée dedans. C'est le contrat de Laravel, et il n'a pas
    | d'exception : `env()` dans config/, `config()` partout ailleurs.
    |
    | Fichier dédié plutôt qu'un `config/app.php` partiel : ce dernier viendrait
    | se superposer aux valeurs par défaut du framework (`key`, `cipher`,
    | `providers`…), et une erreur à cet endroit casserait le chiffrement de
    | toute l'application. Le gain de rangement ne vaut pas ce risque-là.
    |
    */

    'url' => rtrim((string) env('FRONTEND_URL', 'https://qayed.tn'), '/'),

];
