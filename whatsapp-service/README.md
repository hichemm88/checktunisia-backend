# Qayed — Service émetteur WhatsApp (MODULE PROVISOIRE)

> **MODULE PROVISOIRE — à retirer après homologation MI.**
> Voir `PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md`.

Service Node autonome qui tient **une** session WhatsApp (`whatsapp-web.js` +
`LocalAuth`) et envoie les fiches de police à **un destinataire unique**. Il ne
contient aucune logique métier : il réclame les envois au backend Laravel,
récupère la photo, envoie, et rend le verdict. La file, les retries, le journal,
le destinataire et les alertes vivent **côté Laravel**.

```
Check-in complété (Laravel)
        │  enfile (1 message / voyageur)  → table whatsapp_send_log (status=pending)
        ▼
whatsapp-service (Node)  ── GET /internal/whatsapp/next ──▶  réclame le job (FIFO)
        │                 ── GET .../scan/{id}         ──▶  récupère la photo (jamais dupliquée)
        │                 ── client.sendMessage(...)   ──▶  photo + fiche en légende (1 message)
        └───────────────  ── POST .../jobs/{id}/result ──▶  sent (message_id) | failed (error)
```

## Pourquoi un service séparé ?

`whatsapp-web.js` est une bibliothèque **Node** : elle ne peut pas tourner dans
le backend Laravel/PHP. Ce service est donc un *sidecar* déployé à part
(nouveau service Railway), relié à Laravel par une API interne authentifiée par
secret partagé.

## Configuration

Copier `.env.example` → `.env` et renseigner :

| Variable | Rôle |
|----------|------|
| `LARAVEL_API_BASE` | URL du backend, préfixe `/api/v1` inclus |
| `WHATSAPP_WORKER_SECRET` | **identique** à celui du backend Laravel |
| `WHATSAPP_SESSION_PATH` | dossier de session `LocalAuth` (→ volume persistant) |
| `WHATSAPP_SESSION_VAULT_ENABLED` | copie chiffrée de la session côté backend (`1` par défaut) |
| `WHATSAPP_WATCHDOG_MS` | blocage au démarrage → redémarrage, session conservée (défaut 420000) |
| `WHATSAPP_ALLOW_SESSION_WIPE` | ⚠️ `1` autorise l'effacement de la session — ré-appairage volontaire uniquement |
| `PORT` | port du endpoint santé Node (`/health`) |
| `PUPPETEER_EXECUTABLE_PATH` | chemin Chromium (fourni par le Dockerfile) |

Côté Laravel, activer le module :

```
WHATSAPP_POLICE_ENABLED=true
WHATSAPP_RECIPIENT=216XXXXXXXX@c.us
WHATSAPP_WORKER_SECRET=<le même secret que ci-dessus>
```

## Premier démarrage (scan du QR)

```bash
npm install
npm start
```

Un QR s'affiche dans le terminal : le scanner **avec la SIM dédiée Qayed**
(jamais un numéro personnel — risque de ban Meta). La session est ensuite
persistée dans `WHATSAPP_SESSION_PATH` et survit aux redémarrages.

> 📱 **Depuis un téléphone / Railway** : le QR est aussi servi en image sur
> **`GET /qr`** (ex. `https://<service-node>.up.railway.app/qr`). Ouvre cette
> page dans un navigateur et scanne-la avec le téléphone de la SIM Qayed. La
> page se rafraîchit seule et affiche « connectée » une fois la session prête.

> ⚠️ **Railway / conteneurs** : le disque est éphémère. Monter un **volume
> persistant** sur `WHATSAPP_SESSION_PATH`, sinon le QR devra être re-scanné à
> chaque redéploiement.

## Persistance de la session

Le volume est le chemin nominal, mais il est attaché à **une** instance : il ne
survit pas à une recréation du service. Une copie chiffrée part donc aussi dans
le stockage objet des sauvegardes, via le backend, et n'est réclamée au
démarrage que si le volume n'a rien d'exploitable.

**Aucune fonction de ce service n'efface la session** en dehors d'un
`WHATSAPP_ALLOW_SESSION_WIPE=1` explicite. Le watchdog de démarrage, qui le
faisait auparavant au bout de 120 s, était la cause des « déconnexions à chaque
déploiement ».

Détails, garde-fous et procédure de vérification : [`docs/session-whatsapp.md`](../docs/session-whatsapp.md).

## Place sur le volume

Le volume Railway est plafonné (500 Mo sur le plan actuel) et un profil
Chromium en pèse ~100. Trois choses s'y accumulaient sans que rien ne les
reprenne — le volume était à **87 %** le soir de l'incident d'envoi du
09/08/2026 :

- les **caches Chromium du profil vivant** (`Cache`, `Code Cache`,
  `Service Worker/CacheStorage`, `GPUCache`…). Exclus de l'archive parce que
  reconstructibles, mais jamais effacés du disque — c'est le terme dominant ;
- les **profils écartés** (`session.revoked-*`, `session.orphan`), purgés
  uniquement au moment d'en créer un nouveau : après une révocation isolée, ils
  restaient à demeure ;
- le dossier `.restore-staging` d'une restauration dont toutes les tentatives
  échouent.

`sessionStore.reclaimSpace()` reprend cette place **au démarrage**, avant
Chromium : c'est le seul moment où le navigateur ne tient aucun fichier ouvert
(sous Linux, effacer un fichier tenu ouvert ne rend pas un octet). L'ordre va du
moins précieux au plus précieux, et **les credentials ne sont jamais touchés** —
la liste des caches est un allowlist fixe, jamais un motif ; `IndexedDB` et
`Local Storage` n'y figurent pas et ne peuvent pas y arriver par accident.

⚠️ La liste reprise (`RECLAIMABLE`) n'est pas tout à fait celle des exclusions
d'archive (`EXCLUDED`) : le dossier `Default/Service Worker` part **en entier**,
registre compris. N'effacer que ses caches laisserait Chromium avec une
inscription de service worker pointant vers un script absent — état incohérent,
et candidat sérieux à un chargement de WhatsApp Web qui n'aboutit jamais.

L'occupation est journalisée à chaque démarrage et exposée sur `/health`
(`volume.usedRatio`). Au-delà de `WHATSAPP_VOLUME_WARN_RATIO` (0,8 par défaut),
elle est signalée comme un avertissement : un volume plein, c'est un IndexedDB
qui n'écrit plus, donc des envois qui échouent sans raison visible.

## Deux garde-fous de démarrage, et pourquoi il en faut deux

| Garde-fou | Question posée | Se lève sur |
|-----------|----------------|-------------|
| `WHATSAPP_WATCHDOG_MS` (7 min) | « est-ce que quelque chose s'est passé ? » | **tout** signe de vie : QR, authentification, écran de chargement |
| `WHATSAPP_READY_DEADLINE_MS` (15 min) | « est-ce qu'on est devenu utilisable ? » | **`ready` uniquement** |

Le second a été ajouté après le 10/08/2026, où le worker est resté **neuf heures**
en `initializing` sans jamais rien tenter. Le premier avait été désarmé par un
`loading_screen` à 1 %, et le heartbeat — qui bat très bien sur une session qui
n'est pas prête — empêchait l'alerte « worker injoignable » de se déclencher.
Un démarrage qui commence et n'aboutit jamais était le seul état du système que
rien ne reprenait.

À l'échéance : recyclage via le frein commun (donc borné, puis veille et alerte).
**Sauf si un QR est affiché** — là quelqu'un doit scanner, et redémarrer ne
ferait que remplacer le QR qu'on est peut-être en train de lire.

`/health` expose désormais `phase` et `phaseAt` : « initializing » pendant neuf
heures ne disait pas si Chromium n'avait jamais démarré, si WhatsApp Web
chargeait encore, ou si l'authentification était passée sans aboutir — trois
pannes très différentes sous un seul mot.

## Que fait le worker quand un envoi échoue ?

Un échec d'envoi n'est pas un navigateur à jeter. La politique vit dans
[`recovery.js`](recovery.js) et classe chaque erreur avant d'agir :

| Famille | Exemples | Réaction |
|---------|----------|----------|
| `backend` | photo introuvable (404), Laravel en redéploiement, DNS | temporisation — le redémarrage n'y peut rien |
| `job` | destinataire refusé, fiche rejetée, erreur inconnue | on passe à la fiche suivante ; le backoff par fiche vit côté Laravel |
| `page` | envoi sans réponse, `Protocol error`, `Target closed` | escalade ci-dessous |

Escalade sur la famille `page` uniquement, après `WHATSAPP_MAX_SEND_FAILURES`
échecs **consécutifs** :

1. **rechargement** de la page WhatsApp Web (renderer neuf, gratuit pour le
   quota Railway) — jusqu'à `WHATSAPP_MAX_PAGE_RELOADS` fois ;
2. **recyclage du conteneur**, par un arrêt *propre* (Chromium fermé, session
   déposée au coffre) — jamais plus de `WHATSAPP_MAX_SELF_RESTARTS` fois par
   `WHATSAPP_SELF_RESTART_WINDOW_MS` ;
3. **veille technique** quand ce budget est épuisé : le worker cesse d'émettre
   pendant `WHATSAPP_HALT_COOLDOWN_MS`, le dit une fois aux administrateurs, et
   **reste en vie** — `/health`, `/qr` et `/debug` demeurent joignables.

> Pourquoi ce plafond : `restartPolicyMaxRetries: 10` (voir `railway.json`). Au
> dixième crash, Railway arrête le service définitivement — donc aussi `/qr`,
> le seul geste qui aurait pu réparer. Un worker en veille vaut infiniment mieux
> qu'un service éteint.

Le compteur de recyclages est écrit sur le volume (`.qayed-restarts.json`, hors
archive) : un compteur en mémoire serait remis à zéro par ce qu'il doit borner.

Un recyclage volontaire s'annonce au backend en **`initializing`**, pas en
`disconnected` : ce n'est pas une perte de session, et l'annoncer comme telle
envoyait une fausse alerte à chaque tour de boucle.

## Santé

- `GET /health` (ce service) : état local de la session + compteurs, dont
  `phase` / `phaseAt` (où en est le démarrage), `self_restarts_in_window`,
  `sending_suspended`, `suspension_reason` et `volume` (place restante).
- `GET /session-vault?token=…` : session sur le disque + copie en coffre
  (métadonnées seules — jamais un octet de la session).
- `GET /api/v1/health/whatsapp` (Laravel) : état consolidé + profondeur de file.

## Tests

```bash
npm test     # node --test, sans dépendance
```

Couvre la résilience de la session : une session existante n'est jamais
effacée, une instance recréée la retrouve, une session vide ne peut pas écraser
le coffre, et aucun secret n'atteint les journaux.

Couvre aussi la politique de reprise (`recovery.test.js`) : une photo
introuvable ne redémarre pas le conteneur, une page muette est rechargée avant
d'être recyclée, et le nombre de recyclages reste borné même à travers les
redémarrages qu'il compte.

Et la reprise de place (`volume-space.test.js`), dont le test qui prime sur tous
les autres : gagner des mégaoctets ne doit jamais coûter les credentials.

## Sécurité

- La session **ignore tous les messages entrants** (pas de bot, surface nulle).
- Aucun secret en dur : destinataire et secret worker sont des variables d'env.
- Les pages **`/qr`, `/debug` et `/selftest-media` sont protégées par jeton**
  (`WHATSAPP_QR_TOKEN`, passé en `?token=…`) : quiconque voit le QR peut capter
  la session, et `/selftest-media` envoie un vrai message. Sans le bon jeton la
  page répond 404. Reporter le jeton dans `WHATSAPP_QR_URL` côté backend pour
  que le bouton de l'email d'alerte l'inclue automatiquement.
- Pour **couper les envois immédiatement** sans redéployer :
  `POST /api/v1/admin/whatsapp/pause` (le worker cesse d'émettre au prochain tick).
