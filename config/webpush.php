<?php

/*
|--------------------------------------------------------------------------
| Notifications push du CRM de prospection
|--------------------------------------------------------------------------
|
| Web Push standard (RFC 8030), signé VAPID — pas de SDK propriétaire
| (Firebase, OneSignal...), pas de compte externe : n'importe quel
| navigateur compatible (Chrome/Edge/Firefox desktop et Android, Safari
| iOS 16.4+ en PWA installée) peut recevoir des notifications tant que ces
| clés sont posées.
|
| Génération d'une paire de clés (une fois, en local ou en CI) :
|   php artisan tinker --execute="dump(\Minishlink\WebPush\VAPID::createVapidKeys())"
| La clé PUBLIQUE est aussi envoyée au frontend (VITE_VAPID_PUBLIC_KEY,
| build-arg du service Railway) pour que le navigateur s'abonne dessus —
| la PRIVÉE ne quitte jamais le backend.
|
*/

return [

    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    // Contact administratif exigé par la spec VAPID (mailto: ou https://...),
    // utilisé par les services de relais (Mozilla, Google...) en cas d'abus.
    'subject' => env('VAPID_SUBJECT', 'mailto:contact@qayed.tn'),

];
