<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sonde externe du planificateur (dead-man's switch)
    |--------------------------------------------------------------------------
    |
    | URL appelée par le planificateur à chaque tournée. L'alarme vit CHEZ LE
    | TIERS : c'est lui qui prévient quand les appels cessent d'arriver. Aucune
    | tâche interne ne peut jouer ce rôle — elle se tairait en même temps que
    | le composant qu'elle surveille.
    |
    | Vide = sonde inerte, aucun appel sortant. C'est l'état par défaut du
    | dépôt : l'URL porte un jeton et n'a rien à faire dans le code.
    |
    | Voir docs/observabilite.md pour le paramétrage côté service tiers.
    |
    */

    'scheduler_ping_url' => env('SCHEDULER_PING_URL'),

];
