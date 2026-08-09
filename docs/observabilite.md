# Observabilité — ce qui est surveillé, et par qui

Ce document répond à une seule question : **si Qayed s'arrête de travailler
cette nuit, qu'est-ce qui nous le dit ?**

## Le problème de fond

Qayed peut cesser de fonctionner sans tomber. Le serveur web répond `200` sur
`/up` pendant que le planificateur est mort : la file n'est plus drainée, les
fiches de police ne partent plus vers les autorités, les factures de
renouvellement ne sont plus émises. C'est un incident de conformité invisible.

**Une tâche planifiée ne peut pas servir d'alarme sur la mort du
planificateur.** Si le composant surveillé est aussi celui qui surveille, son
silence est indiscernable de « tout va bien ». Toute alerte interne
supplémentaire donnerait une fausse sécurité.

## Ce qui existe aujourd'hui

### 1. Le battement du planificateur

`routes/console.php` écrit `scheduler:last_run_at` en cache chaque minute.
`GET /admin/health` expose `scheduler.last_run_at`, `scheduler.minutes_since`
et `scheduler.stale` (au-delà de 5 minutes).

L'observateur est **le navigateur de l'administrateur** — extérieur au système
observé. Le tableau de bord admin affiche l'âge du dernier battement.

**Portée réelle :** couvre les heures ouvrées, quand quelqu'un regarde. Ne
couvre ni la nuit ni le week-end.

### 2. Les autres signaux de `GET /admin/health`

| Signal | Ce qu'il révèle |
|---|---|
| `database.reachable` / `latency_ms` | base injoignable ou lente |
| `queue.pending` | file qui gonfle = worker mort |
| `queue.failed_total` | jobs abandonnés (détail : `/admin/health/failed-jobs`, rejouables) |
| `scheduler.stale` | planificateur muet |
| `backup.stale` / `hours_since_success` | registre non protégé (Railway n'offre ni sauvegarde native ni PITR) |
| `whatsapp.pending` / `failed` | fiches en attente ou en échec |
| `whatsapp.session.status` | `logged_out` = ré-appairage QR requis, rien ne partira |
| `whatsapp.session.last_ready_at` | depuis quand la session n'est plus opérationnelle |

Une file WhatsApp à zéro est ambiguë : « tout est parti » ou « rien ne peut
partir ». C'est le statut de session qui tranche.

### 3. Les alertes qui partent déjà par email

- **Sauvegarde en échec** — `qayed:db-backup` (`onFailure` + Sentry).
- **Worker WhatsApp silencieux** — `whatsapp:check-health` toutes les 10 min.
- **Session WhatsApp révoquée (LOGOUT)** — dédupliquée, une seule alerte.

Ces trois-là dépendent du planificateur. **Elles se taisent en même temps que
lui** — d'où la section suivante.

## Ce qu'il reste à brancher (action manuelle, hors code)

Une sonde **externe au déploiement**. Rien de ce qui tourne dans le conteneur
Railway ne peut garantir de crier quand ce conteneur se tait.

**À mettre en place :** un moniteur d'uptime tiers (UptimeRobot, Better Stack,
Healthchecks.io — tous ont une offre gratuite suffisante ici).

Deux montages possibles, du plus simple au plus fiable :

1. **Sonde HTTP sur `/up`** (5 min, alerte email/SMS).
   Détecte : conteneur mort, déploiement raté, base injoignable au démarrage.
   Ne détecte PAS : planificateur mort alors que le web répond.

2. **Dead-man's switch sur le planificateur** — *le montage qui manque
   réellement*. Créer un check « ping attendu toutes les 5 minutes » sur
   Healthchecks.io, puis appeler son URL depuis `routes/console.php` à côté du
   battement existant. Le service alerte quand le ping **cesse d'arriver** :
   l'alarme vit chez le tiers, pas chez nous, et c'est précisément ce qui la
   rend valable.

   Une variable d'environnement suffit (`SCHEDULER_PING_URL`), et l'appel doit
   être en `try/catch` silencieux : une sonde ne doit jamais faire échouer une
   tâche métier.

Tant que le point 2 n'est pas branché, **la mort du planificateur en dehors
des heures ouvrées reste non détectée**. C'est le trou d'observabilité connu
et assumé du déploiement actuel.

## Suivi des erreurs applicatives

Sentry est actif côté backend et frontend, expurgé de toute donnée
personnelle : ni corps de requête, ni cookies, ni requêtes SQL en fil
d'Ariane (les valeurs liées contiennent noms et numéros de documents). Voir
`ObservabilityTest`.
