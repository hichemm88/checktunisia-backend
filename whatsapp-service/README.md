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

> ⚠️ **Railway / conteneurs** : le disque est éphémère. Monter un **volume
> persistant** sur `WHATSAPP_SESSION_PATH`, sinon le QR devra être re-scanné à
> chaque redéploiement.

## Santé

- `GET /health` (ce service) : état local de la session + compteurs.
- `GET /api/v1/health/whatsapp` (Laravel) : état consolidé + profondeur de file.

## Sécurité

- La session **ignore tous les messages entrants** (pas de bot, surface nulle).
- Aucun secret en dur : destinataire et secret worker sont des variables d'env.
- Pour **couper les envois immédiatement** sans redéployer :
  `POST /api/v1/admin/whatsapp/pause` (le worker cesse d'émettre au prochain tick).
