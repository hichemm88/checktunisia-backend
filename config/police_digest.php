<?php

/*
|--------------------------------------------------------------------------
| Récapitulatif quotidien des fiches de police
|--------------------------------------------------------------------------
|
| Un PDF unique regroupant les arrivées du jour de TOUS les établissements du
| destinataire, pièces d'identité comprises, envoyé par email chaque soir.
|
| Mis en place pendant l'absence de l'exploitant (18–28/08/2026), le relais
| WhatsApp étant hors service — numéro émetteur restreint par Meta. Le
| destinataire retransmet lui-même aux autorités.
|
| Tout est piloté par variables d'environnement : ni date ni adresse en dur,
| la prolongation ou l'arrêt ne demandent aucun redéploiement.
|
*/

return [

    // Destinataire du récapitulatif. Vide => tâche totalement inerte.
    'recipient' => env('POLICE_DIGEST_RECIPIENT'),

    /*
    | Dernier jour d'envoi (AAAA-MM-JJ), inclus. Au-delà, la tâche planifiée ne
    | fait plus rien — l'envoi s'arrête de lui-même au retour de l'exploitant,
    | sans qu'il faille y penser. Vide => aucune limite.
    */
    'until' => env('POLICE_DIGEST_UNTIL'),

    /*
    | Établissements à inclure, identifiants séparés par des virgules.
    |
    | Vide => déduits du destinataire : les établissements de SON organisation,
    | plus ceux qui lui sont explicitement rattachés. Ce périmètre n'est pas un
    | détail de confort : Qayed héberge les voyageurs de plusieurs clients, et
    | envoyer à une boîte personnelle les pièces d'identité des clients d'autrui
    | serait une violation caractérisée. La commande refuse d'élargir seule.
    */
    'hotels' => env('POLICE_DIGEST_HOTELS'),

    // Heure d'envoi, fuseau Africa/Tunis (voir routes/console.php).
    'hour' => env('POLICE_DIGEST_HOUR', '17:00'),

];
