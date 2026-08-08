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

## Santé

- `GET /health` (ce service) : état local de la session + compteurs.
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
