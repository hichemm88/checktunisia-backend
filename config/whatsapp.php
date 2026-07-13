<?php

/*
|--------------------------------------------------------------------------
| WhatsApp police check-in relay — MODULE PROVISOIRE
|--------------------------------------------------------------------------
|
| MODULE PROVISOIRE — à retirer après homologation MI.
| Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
|
| Solution transitoire : à chaque check-in complété, la fiche + la photo du
| document sont poussées par WhatsApp à UN destinataire unique (le
| propriétaire, qui transfère manuellement au poste de police). L'envoi réel
| est effectué par un service Node dédié (whatsapp-service/) via whatsapp-web.js
| — impossible à embarquer dans PHP. Laravel se contente d'enfiler, de
| planifier les retries, de journaliser et d'exposer l'admin.
|
| Architecture volontairement minimale : 1 émetteur → 1 destinataire.
| Tout est piloté par variables d'environnement, désactivable sans redéploiement.
|
*/

return [

    // Interrupteur général. À false : aucun enfilage, aucun worker, aucune
    // erreur — le module est totalement inerte (critère d'acceptation §5).
    'enabled' => (bool) env('WHATSAPP_POLICE_ENABLED', false),

    // Destinataire unique, format whatsapp-web.js : "216XXXXXXXX@c.us".
    // Secret de configuration — jamais en dur dans le code.
    'recipient' => env('WHATSAPP_RECIPIENT'),

    // Secret partagé entre Laravel et le service Node. Le worker s'authentifie
    // avec ce jeton sur les routes /api/v1/internal/whatsapp/*.
    'worker_secret' => env('WHATSAPP_WORKER_SECRET'),

    // Délai minimum entre deux envois, appliqué côté worker (anti-ban Meta).
    'min_interval_seconds' => (int) env('WHATSAPP_MIN_INTERVAL_SECONDS', 3),

    // Backoff des retries, en minutes depuis le premier échec du job.
    // 1 min, 5 min, 15 min, 1 h, puis toutes les 4 h jusqu'à 24 h max.
    // Au-delà : statut `failed` + alerte admin.
    'retry_schedule_minutes' => [1, 5, 15, 60, 240, 480, 720, 960, 1200, 1440],

    // Âge maximum d'un job avant abandon définitif (24 h).
    'max_age_minutes' => (int) env('WHATSAPP_MAX_AGE_MINUTES', 1440),
];
