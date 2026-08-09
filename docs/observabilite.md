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
lui** — d'où la sonde externe ci-dessous, qui est le seul témoin dont l'alarme
ne vit pas dans le système qu'elle surveille.

### 4. La sonde externe (dead-man's switch) — CODE LIVRÉ, à configurer

C'est le seul témoin qui couvre la nuit et le week-end. Le planificateur
appelle une URL tierce toutes les 5 minutes ; **le service tiers alerte quand
les appels cessent d'arriver**. L'alarme vit chez lui, pas chez nous — c'est
exactement ce qui la rend valable.

Côté dépôt, tout est en place :

| Élément | Rôle |
|---|---|
| `SCHEDULER_PING_URL` (env) | l'URL du check. **Vide = sonde inerte**, aucun appel sortant |
| `config/monitoring.php` | lecture de la variable, aucune valeur en dur |
| `SchedulerHeartbeat::ping()` | appel, timeout 5 s, jamais bloquant, n'écrit jamais l'URL dans les logs |
| `routes/console.php` | tâche `scheduler-external-probe`, toutes les 5 min |
| `php artisan qayed:scheduler-ping` | test manuel du branchement |

L'URL **est un jeton** : qui la connaît peut faire taire l'alarme. Elle se
traite comme un secret — jamais dans le dépôt, jamais dans un log, jamais
dans une capture d'écran.

#### Ce qu'il reste à faire, une seule fois (~10 minutes)

1. Créer un compte sur **Healthchecks.io** (l'offre gratuite suffit : 20
   checks). Tout service équivalent acceptant un ping HTTP convient.
2. Créer un check nommé `qayed-scheduler` avec :
   - **Period : 5 minutes** (la cadence de la tâche) ;
   - **Grace time : 10 minutes** (tolère un déploiement ou un pic de charge
     sans crier au loup).
   Détection effective d'un planificateur mort : **sous 15 minutes**.
3. Copier l'URL de ping du check (`https://hc-ping.com/<uuid>`).
4. Dans **Railway → service backend → Variables**, ajouter
   `SCHEDULER_PING_URL` avec cette valeur. Le service redémarre seul.
5. Vérifier le branchement :
   ```
   railway run php artisan qayed:scheduler-ping
   ```
   Attendu : « Sonde externe prévenue. » — et le check passe au vert dans
   Healthchecks.
6. Renseigner la destination d'alerte dans Healthchecks (email, SMS).
   **Ne pas router l'alerte vers un canal hébergé par Qayed** : une alarme
   qui transite par le système en panne ne sonne pas.

**Tant que l'étape 4 n'est pas faite, la mort du planificateur hors heures
ouvrées n'est pas détectée.** Le code est inerte et ne provoque aucun appel
sortant : il n'y a aucun risque à le déployer avant de configurer.

### 5. Sonde HTTP simple sur `/up` (complémentaire, facultative)

Détecte : conteneur mort, déploiement raté, base injoignable au démarrage.
Ne détecte PAS : planificateur mort alors que le serveur web répond — d'où le
point 4.

## Suivi des erreurs applicatives

Sentry est actif côté backend et frontend, expurgé de toute donnée
personnelle : ni corps de requête, ni cookies, ni requêtes SQL en fil
d'Ariane (les valeurs liées contiennent noms et numéros de documents). Voir
`ObservabilityTest`.
