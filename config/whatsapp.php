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

    // Délai minimum entre deux envois, appliqué côté worker (anti-ban Meta).
    'min_interval_seconds' => (int) env('WHATSAPP_MIN_INTERVAL_SECONDS', 3),

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
    | 'web'   = relais WhatsApp Web non officiel (worker Node). HISTORIQUE —
    |           le numéro émetteur a été banni par WhatsApp : ce canal ne
    |           transmet plus rien. Conservé comme retour arrière d'urgence.
    | 'cloud' = API Cloud officielle (Meta Graph). DÉFAUT depuis la migration.
    |
    | `WHATSAPP_PROVIDER=legacy` est accepté comme alias de repli (= 'web') :
    | une seule variable à poser pour rebasculer, sans connaître le nom interne
    | des canaux. WHATSAPP_CHANNEL, s'il est posé, l'emporte.
    |
    | Voir docs/canal-transmission.md pour la procédure de bascule.
    */
    'channel' => env('WHATSAPP_CHANNEL', env('WHATSAPP_PROVIDER') === 'legacy' ? 'web' : 'cloud'),

    /*
    | Mode ombre : le canal cible est exercé À BLANC sur chaque job (résolution
    | des destinataires, formatage, validation) et le résultat est journalisé,
    | SANS rien transmettre. Permet de comparer les deux canaux sur du trafic
    | réel avant de basculer.
    */
    'shadow_channel' => env('WHATSAPP_SHADOW_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta Graph) — canal officiel
    |--------------------------------------------------------------------------
    |
    | Les noms de variables posés en production sont ceux de la console Meta
    | (WHATSAPP_API_TOKEN, WHATSAPP_PHONE_NUMBER_ID…). Les noms historiques
    | WHATSAPP_CLOUD_* restent acceptés en repli pour ne casser aucun
    | environnement déjà configuré. AUCUN de ces secrets n'a de valeur par
    | défaut : un canal non configuré doit refuser d'envoyer, pas improviser.
    */
    'cloud' => [
        'token' => env('WHATSAPP_API_TOKEN', env('WHATSAPP_CLOUD_TOKEN')),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_CLOUD_PHONE_NUMBER_ID')),
        'waba_id' => env('WHATSAPP_WABA_ID'),
        'base_url' => env('WHATSAPP_CLOUD_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('WHATSAPP_API_VERSION', env('WHATSAPP_CLOUD_API_VERSION', 'v21.0')),
        'timeout' => (int) env('WHATSAPP_CLOUD_TIMEOUT', 30),

        /*
        | Webhook Meta. `verify_token` répond au défi de vérification (GET),
        | `app_secret` signe chaque livraison (X-Hub-Signature-256). Sans
        | app_secret, le POST est REFUSÉ : mieux vaut ne rien traiter que
        | traiter des accusés de réception non authentifiés.
        */
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),

        // Identifiant de l'app Meta. Sert à composer le jeton d'application
        // « {app_id}|{app_secret} » qu'exige l'enregistrement du webhook.
        'app_id' => env('WHATSAPP_APP_ID'),

        // URL de rappel déclarée à Meta. Le préfixe d'API du projet est
        // /api/v1 : c'est cette forme-là qui doit être enregistrée.
        'webhook_callback_url' => env(
            'WHATSAPP_WEBHOOK_CALLBACK_URL',
            rtrim(env('APP_URL', 'https://api.qayed.tn'), '/').'/api/v1/webhooks/whatsapp',
        ),

        /*
        | Modèle de message.
        |
        | Hors fenêtre de 24 h — le cas NORMAL ici, personne ne répond aux
        | fiches — la Cloud API n'accepte que des modèles approuvés. Un modèle
        | ne porte qu'un seul média : les photos de documents ne transitent
        | donc plus par WhatsApp. Le destinataire consulte la fiche complète,
        | pièces comprises, derrière le bouton — ce qui est aussi une meilleure
        | hygiène de données personnelles.
        */
        'template' => [
            'name' => env('WHATSAPP_TEMPLATE_NAME', 'fiche_police_nouvelle'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'fr'),

            /*
            | Base de l'URL du bouton « Consulter la fiche ». Le suffixe
            | dynamique {{1}} est l'identifiant du voyageur : la page
            | /authority/guests/{id} du portail autorité existe déjà et exige
            | une authentification — l'identifiant est opaque, il ne divulgue
            | rien à lui seul.
            |
            | TODO : quand une page « fiche » dédiée (jeton signé, sans
            | compte) existera, c'est ici qu'il faudra la pointer — et
            | soumettre le modèle à nouveau, l'URL de base étant figée dans
            | l'approbation Meta.
            */
            'fiche_url_base' => env(
                'WHATSAPP_FICHE_URL_BASE',
                rtrim(env('FRONTEND_URL', 'https://qayed.tn'), '/').'/authority/guests/',
            ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Garde-fous d'envoi (Cloud API)
    |--------------------------------------------------------------------------
    |
    | Le numéro émetteur est NEUF et la vérification d'entreprise est encore en
    | cours : la réputation se joue sur les premiers jours d'envoi. Un pic
    | involontaire — reprise d'un arriéré, boucle de retry, incident — vaudrait
    | un bannissement, comme le précédent numéro.
    |
    | Ces garde-fous ne remplacent pas la file : ils la RALENTISSENT. Rien
    | n'est jeté, tout reste en attente et repart au créneau suivant.
    */
    'guard' => [

        /*
        | Coupe-circuit. À false, plus AUCUN envoi ne part — les fiches restent
        | en attente, rien n'est perdu. Relu à chaque envoi (et non au
        | démarrage) : c'est ce qui en fait un vrai coupe-circuit, actionnable
        | en quelques secondes depuis Railway.
        */
        'sending_enabled' => (bool) env('WHATSAPP_SENDING_ENABLED', true),

        /*
        | Instant de bascule vers la Cloud API (ISO 8601).
        |
        | AUCUNE entrée créée AVANT cet instant ne partira, quelle que soit la
        | façon dont on la relance — renvoi manuel compris. C'est la protection
        | contre l'arriéré accumulé depuis le bannissement du relais Web :
        | plusieurs centaines de fiches de séjours terminés, dont l'envoi en
        | rafale depuis un numéro neuf ferait bannir celui-ci à son tour.
        |
        | Non définie = la Cloud API n'envoie RIEN. Défaut volontairement
        | paralysant : une bascule qui s'active toute seule au déploiement est
        | exactement l'accident qu'on cherche à éviter.
        */
        'cutover_at' => env('WHATSAPP_CLOUD_API_CUTOVER_AT'),

        // Débit maximum, global à l'émetteur. Au-delà, la file attend le
        // créneau suivant — elle n'est jamais vidée ni tronquée.
        'max_sends_per_minute' => (int) env('WHATSAPP_MAX_SENDS_PER_MINUTE', 20),

        // Plafond quotidien (jour civil, Africa/Tunis). Atteint : mise en
        // attente jusqu'au lendemain et alerte admin.
        'max_sends_per_day' => (int) env('WHATSAPP_MAX_SENDS_PER_DAY', 500),

        /*
        | Au-delà de ce nombre de fiches en attente, l'envoi automatique
        | S'ARRÊTE et réclame une action humaine.
        |
        | C'est la leçon directe de l'incident : un arriéré n'est pas un volume
        | de travail, c'est le symptôme d'une panne. Le vider automatiquement
        | transforme une panne silencieuse en rafale vers des officiels.
        */
        'backlog_alert_threshold' => (int) env('WHATSAPP_BACKLOG_ALERT_THRESHOLD', 50),

        // Durée de la pause globale déclenchée par une erreur de débit ou de
        // qualité côté Meta (131049, 80007).
        'quality_pause_minutes' => (int) env('WHATSAPP_QUALITY_PAUSE_MINUTES', 15),

        // Destination du rapport CSV des fiches annulées par la commande
        // whatsapp:cancel-backlog. Hors du dépôt : storage/ est ignoré par git.
        'backlog_report_path' => env('WHATSAPP_BACKLOG_REPORT_PATH'),
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
