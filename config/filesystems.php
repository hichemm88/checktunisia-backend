<?php

/*
|--------------------------------------------------------------------------
| Disques de stockage
|--------------------------------------------------------------------------
|
| Ce fichier n'existait pas. Conséquence : la clé `filesystems.passport_scan_disk`,
| lue par CheckInService::uploadScan() et par le worker WhatsApp, n'était définie
| nulle part — seul le défaut en dur `'local'` évitait la casse. Poser
| PASSPORT_SCAN_DISK=s3 en production n'aurait eu AUCUN effet : on aurait cru
| avoir déplacé les documents d'identité vers un stockage objet alors qu'ils
| seraient restés sur le disque éphémère du conteneur.
|
*/

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disque des scans de documents d'identité (CIN, passeports).
    | Doit rester un disque PRIVÉ : aucune URL publique ne doit exister.
    */
    'passport_scan_disk' => env('PASSPORT_SCAN_DISK', 'local'),

    'disks' => [

        // Privé par défaut sous Laravel 12 : storage/app/private, sans lien
        // symbolique depuis public/. Ne pas rendre ce disque public.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Stockage objet compatible S3 (Backblaze B2, Cloudflare R2, AWS S3…).
        // Utilisé pour les scans si PASSPORT_SCAN_DISK=s3.
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        | Sauvegardes de base HORS FOURNISSEUR.
        |
        | Volontairement distinct du disque `s3` applicatif : une sauvegarde
        | stockée chez le même fournisseur que la base ne protège pas du
        | scénario qui compte — la perte du compte ou du projet.
        | Idéalement un autre fournisseur, et un jeu d'identifiants dédié
        | en écriture seule.
        */
        'backups' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),

            // Cloudflare R2 n'a pas de régions au sens AWS : il exige
            // littéralement « auto ». Toute autre valeur fait échouer la
            // signature de la requête.
            'region' => env('BACKUP_S3_REGION', 'auto'),

            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),

            // R2 sert le bucket dans le CHEMIN
            // (https://<compte>.r2.cloudflarestorage.com/<bucket>/<clé>) et non
            // en sous-domaine. Le style virtuel ne résoudrait pas.
            'use_path_style_endpoint' => env('BACKUP_S3_PATH_STYLE', true),

            /*
            | Compatibilité Cloudflare R2 — checksums de flux.
            |
            | Depuis la version 3.337, le SDK AWS calcule un checksum CRC32 sur
            | chaque PutObject (DEFAULT_CALCULATION_MODE = 'when_supported',
            | voir S3/ApplyChecksumMiddleware.php). Sur un envoi en FLUX — ce
            | que fait la commande de sauvegarde, qui passe un handle de
            | fichier — le SDK bascule alors en encodage « aws-chunked » avec
            | checksum en fin de trame. R2 ne prend PAS en charge ce mode et
            | rejette la requête.
            |
            | 'when_required' n'ajoute le checksum que lorsque l'opération
            | l'impose réellement. L'intégrité n'est pas dégradée pour autant :
            | l'archive est chiffrée en XChaCha20-Poly1305, donc AUTHENTIFIÉE
            | de bout en bout — toute altération d'un octet est détectée au
            | déchiffrement, ce qu'un CRC32 de transport ne garantit même pas.
            |
            | Laravel transmet cette clé telle quelle au client S3 : le
            | FilesystemManager ne filtre que 'token' (Arr::except).
            */
            'request_checksum_calculation' => env('BACKUP_S3_CHECKSUM_MODE', 'when_required'),

            // R2 ne supporte pas les ACL : aucune 'visibility' ne doit être
            // déclarée ici, sinon Flysystem enverrait un en-tête x-amz-acl que
            // R2 rejette. Absence volontaire, ne pas « compléter ».
            'throw' => true,
            'report' => false,
        ],

    ],

    'links' => [],

];
