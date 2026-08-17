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

    // Envoi direct aux agents assignés à l'établissement (Phase 3). Interrupteur
    // de secours : si false, TOUT retombe sur le numéro global ci-dessus, quel
    // que soit le rattachement établissement→agents. À true, un établissement
    // AVEC des destinataires assignés envoie directement ; sans, il garde le
    // numéro global. Défaut true (le rattachement par établissement fait la bascule).
    'direct_routing' => (bool) env('WHATSAPP_DIRECT_ROUTING', true),

    // Secret partagé entre Laravel et le service Node. Le worker s'authentifie
    // avec ce jeton sur les routes /api/v1/internal/whatsapp/*.
    'worker_secret' => env('WHATSAPP_WORKER_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Garde-fous anti-restriction Meta
    |--------------------------------------------------------------------------
    |
    | Le 17/08/2026, le numéro émetteur a été restreint 6 h par WhatsApp
    | (« activité laissant penser à du spam, à un message automatisé ou à un
    | envoi groupé »). Le profil cochait toutes les cases de l'heuristique : un
    | numéro fraîchement appairé, 100 % sortant — le module ignore tout message
    | entrant, par exigence de sécurité —, une pièce jointe à chaque message,
    | des discussions ouvertes avec des destinataires qui n'ont jamais écrit, et
    | une rafale à 3 s d'intervalle. Les réglages ci-dessous existent pour que
    | ce profil ne se reproduise pas, et pour que le worker s'arrête au lieu de
    | s'obstiner si Meta bloque quand même.
    |
    */

    // Délai minimum entre deux envois, appliqué côté worker.
    // 3 s à l'origine : trop rapide, et surtout METRONOMIQUE — une régularité
    // à la milliseconde près est en soi une signature d'automate.
    'min_interval_seconds' => (int) env('WHATSAPP_MIN_INTERVAL_SECONDS', 45),

    // Part d'aléa appliquée à ce délai (0.4 = ±40 %). Casse la régularité.
    'interval_jitter_ratio' => (float) env('WHATSAPP_INTERVAL_JITTER_RATIO', 0.4),

    // Plafond d'envois par heure glissante, appliqué côté Laravel — le worker
    // perd la mémoire à chaque redémarrage, le backend non. Une file de 200
    // fiches (arriéré après panne) ne doit JAMAIS partir d'un bloc.
    'max_per_hour' => (int) env('WHATSAPP_MAX_PER_HOUR', 30),

    /*
    | Montée en charge après appairage. Un numéro neuf qui se met aussitôt à
    | émettre est le cas d'école du compte jetable ; c'est exactement ce qui
    | s'est produit après le ré-appairage avec un autre numéro, arriéré compris.
    | Pendant cette fenêtre, cadence et plafond sont volontairement bas.
    |
    | La fenêtre redémarre quand le NUMÉRO connecté change (voir
    | WhatsappSessionState::phone_number) — pas à chaque reconnexion du même
    | numéro, qui ne repart pas de zéro en réputation.
    */
    'warmup_hours' => (int) env('WHATSAPP_WARMUP_HOURS', 24),
    'warmup_max_per_hour' => (int) env('WHATSAPP_WARMUP_MAX_PER_HOUR', 6),
    'warmup_min_interval_seconds' => (int) env('WHATSAPP_WARMUP_MIN_INTERVAL_SECONDS', 120),

    /*
    | Disjoncteur. N échecs d'envoi consécutifs SANS panne de page (la page
    | répond, c'est WhatsApp qui refuse) = blocage au niveau du compte. Réessayer
    | ne répare rien et aggrave : chaque tentative est une infraction de plus, et
    | c'est ainsi qu'une restriction de 6 h devient un bannissement définitif.
    | Au déclenchement le relais est mis en pause EN BASE (donc durablement, y
    | compris après redémarrage du worker) et les administrateurs sont alertés.
    | La reprise est un geste humain : bouton « Reprendre ».
    */
    'circuit_breaker_failures' => (int) env('WHATSAPP_CIRCUIT_BREAKER_FAILURES', 5),

    // Backoff des retries, en minutes depuis le premier échec du job.
    // 1 min, 5 min, 15 min, 1 h, puis toutes les 4 h jusqu'à 24 h max.
    // Au-delà : statut `failed` + alerte admin.
    'retry_schedule_minutes' => [1, 5, 15, 60, 240, 480, 720, 960, 1200, 1440],

    // Âge maximum d'un job avant abandon définitif (24 h).
    'max_age_minutes' => (int) env('WHATSAPP_MAX_AGE_MINUTES', 1440),

    // Rétention des images de documents (heures). Minimisation des données : les
    // scans ne sont conservés que le temps nécessaire aux envois (retries max
    // 24 h), puis purgés automatiquement. Aligné sur max_age_minutes.
    'image_retention_hours' => (int) env('WHATSAPP_IMAGE_RETENTION_HOURS', 24),

    // URL publique de la page /qr du service Node (whatsapp-service) — insérée
    // en bouton dans l'email d'alerte de déconnexion pour reconnecter en un clic.
    // Le QR lui-même ne peut pas être mis dans l'email : il tourne toutes les
    // ~30 s, il serait expiré à l'ouverture. Vide => bouton omis.
    'qr_url' => env('WHATSAPP_QR_URL'),

    /*
    | Canal de transmission actif (STRAT-07).
    |
    | 'web'   = relais WhatsApp Web non officiel (worker Node). Défaut, et
    |           comportement historique : rien ne change tant qu'on n'y touche pas.
    | 'cloud' = API Cloud officielle (Meta Graph). Cible avant le 10/09/2026,
    |           date d'expiration du contournement par pin de version.
    |
    | Voir docs/canal-transmission.md pour la procédure de bascule.
    */
    'channel' => env('WHATSAPP_CHANNEL', 'web'),

    /*
    | Mode ombre : le canal cible est exercé À BLANC sur chaque job (résolution
    | des destinataires, formatage, validation) et le résultat est journalisé,
    | SANS rien transmettre. Permet de comparer les deux canaux sur du trafic
    | réel avant de basculer.
    */
    'shadow_channel' => env('WHATSAPP_SHADOW_CHANNEL'),

    'cloud' => [
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
        'base_url' => env('WHATSAPP_CLOUD_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_CLOUD_API_VERSION', 'v21.0'),
        'timeout' => (int) env('WHATSAPP_CLOUD_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Coffre de session (persistance durable de l'appairage WhatsApp Web)
    |--------------------------------------------------------------------------
    |
    | La session WhatsApp Web (profil Chromium appairé au téléphone) vivait
    | UNIQUEMENT sur le volume Railway du service worker. Un volume est lié à
    | une instance : il survit à un redéploiement, mais pas à une recréation du
    | service, à une migration de région, ni à une erreur d'opération. Le seul
    | moyen de retrouver la session était alors de re-scanner un QR — c'est-à-dire
    | une interruption du canal de transmission légal du produit.
    |
    | Le coffre archive la session dans le MÊME stockage objet chiffré que les
    | sauvegardes de base (disque « backups », chiffrement XChaCha20-Poly1305 du
    | trousseau BackupKeyring). Aucun nouveau secret, aucun nouveau fournisseur.
    |
    | Le worker est le seul client : il dépose une archive après appairage puis
    | périodiquement, et la réclame au démarrage UNIQUEMENT si son disque local
    | n'a pas de session exploitable. Voir whatsapp-service/session-store.js.
    */
    'session_vault' => [
        'enabled' => (bool) env('WHATSAPP_SESSION_VAULT_ENABLED', true),

        // Clé unique dans le bucket : on ne garde qu'une session courante (plus
        // une copie de sûreté, écrite par le service avant tout remplacement).
        'path' => env('WHATSAPP_SESSION_VAULT_PATH', 'whatsapp-session/session.tar.gz.enc'),

        /*
        | Plancher de taille, en octets. Une archive plus petite ne peut pas
        | contenir un profil Chromium appairé : c'est le garde-fou qui empêche
        | qu'une session vide (worker démarré sans volume, extraction ratée)
        | vienne ÉCRASER des credentials valides dans le coffre.
        */
        'min_bytes' => (int) env('WHATSAPP_SESSION_VAULT_MIN_BYTES', 65536),

        // Plafond, en octets. Doit rester sous post_max_size (voir Dockerfile).
        'max_bytes' => (int) env('WHATSAPP_SESSION_VAULT_MAX_BYTES', 64 * 1024 * 1024),
    ],

];
