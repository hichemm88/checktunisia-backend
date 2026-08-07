<?php

/*
|--------------------------------------------------------------------------
| Sauvegarde de la base de production
|--------------------------------------------------------------------------
|
| Railway ne fournit ni sauvegarde native ni PITR sur le plan actuel : cette
| sauvegarde n'est donc PAS un complément, c'est la SEULE protection du
| registre. Chaque garde-fou ci-dessous est dimensionné en conséquence.
|
*/

return [

    /*
    | Fenêtre de fraîcheur, en heures.
    |
    | La tâche planifiée s'exécute toutes les heures et ne fait rien si une
    | sauvegarde a réussi depuis moins longtemps que cette fenêtre. C'est ce
    | qui rend le dispositif insensible à une minute manquée : au lieu de
    | dépendre d'un instant précis dans la journée, on rattrape à l'heure
    | suivante. Une exécution ratée ne coûte plus une journée de registre.
    */
    'interval_hours' => (int) env('BACKUP_INTERVAL_HOURS', 24),

    /*
    | Au-delà de ce seuil, l'endpoint de santé signale la sauvegarde comme
    | périmée. Volontairement supérieur à interval_hours pour laisser passer
    | un rattrapage sans crier au loup.
    */
    'stale_after_hours' => (int) env('BACKUP_STALE_AFTER_HOURS', 26),

    /*
    | Rétention, en jours. Assez long pour couvrir une corruption découverte
    | tardivement, assez court pour ne pas conserver indéfiniment des données
    | d'identité de voyageurs — la minimisation s'applique aux sauvegardes.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    | Espace disque libre minimum exigé avant de lancer le dump, en méga-octets.
    | Le dump transite par le disque éphémère du conteneur : mieux vaut refuser
    | de démarrer que remplir le disque et faire tomber le serveur web avec.
    */
    'min_free_disk_mb' => (int) env('BACKUP_MIN_FREE_DISK_MB', 512),

    /*
    | Taille en dessous de laquelle un dump est jugé suspect (octets). Un dump
    | quasi vide signale un échec silencieux (droits insuffisants, mauvaise
    | base) : on refuse de l'archiver plutôt que de le découvrir le jour de la
    | restauration.
    */
    'min_dump_bytes' => (int) env('BACKUP_MIN_DUMP_BYTES', 1024),

    'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 1800),

    /*
    |--------------------------------------------------------------------------
    | Chiffrement
    |--------------------------------------------------------------------------
    |
    | XChaCha20-Poly1305 en mode « secretstream » (libsodium) : chiffrement
    | AUTHENTIFIÉ et en flux, primitive du cœur de PHP. Rien d'artisanal.
    |
    | La clé ne vit QUE dans l'environnement d'exécution. Elle n'est jamais
    | écrite dans le bucket : quiconque obtient les sauvegardes sans la clé
    | n'obtient rien d'exploitable.
    |
    | ROTATION — chaque fichier embarque en clair l'IDENTIFIANT de la clé qui
    | l'a chiffré (jamais la clé). Les anciennes clés restent déclarées dans
    | `previous_keys`, si bien qu'une rotation n'invalide PAS l'historique :
    | les nouvelles sauvegardes utilisent la nouvelle clé, les anciennes
    | restent déchiffrables avec la leur.
    */
    'encryption' => [
        // Identifiant court de la clé courante (ex. « k1 »). Non secret :
        // il est écrit en clair dans l'en-tête du fichier.
        'key_id' => env('BACKUP_ENCRYPTION_KEY_ID', 'k1'),

        // Clé courante : 32 octets encodés en base64. SECRET.
        'key' => env('BACKUP_ENCRYPTION_KEY'),

        // Clés retirées du service, conservées pour déchiffrer l'historique.
        // Format : « k1:base64clé,k2:base64clé ». SECRET.
        'previous_keys' => env('BACKUP_ENCRYPTION_PREVIOUS_KEYS'),
    ],

];
