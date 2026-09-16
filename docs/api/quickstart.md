# Démarrage rapide — API publique Qayed v1

Cette API permet à votre plateforme (PMS, moteur de réservation…) de créer une
fiche de police pour un séjour et de la faire remplir par le réceptionniste ou
le voyageur via le **widget Qayed embarqué**, sur le modèle *Stripe Checkout* :
vous créez une session, le widget fait le travail réglementaire, un webhook
vous notifie le résultat.

Référence complète : [`openapi.yaml`](./openapi.yaml). Vérification de
signature webhook : [`examples/verify-webhook.js`](./examples/verify-webhook.js)
(Node.js) et [`examples/verify-webhook.php`](./examples/verify-webhook.php)
(PHP pur). Intégration du widget côté front : voir
[`widget-integration.html`](/docs/snippets/widget-integration.html)
(modale iframe + gestion des messages `postMessage`).

Toutes les requêtes ci-dessous utilisent une clé de **mode test**
(`qyd_test_...`) — remplacez-la par votre clé `qyd_live_...` une fois validée.
En mode test, aucun message n'est envoyé sur le relais WhatsApp réel et
l'événement `fiche.submitted` n'est pas déclenché (`fiche.failed` et
`session.expired` le sont).

---

## 1. Lier un établissement à votre compte partenaire

Le propriétaire de l'établissement génère un **code de liaison** (valable 24h,
usage unique) depuis son espace Qayed et vous le communique. Échangez-le
contre un lien durable :

```bash
curl -X POST https://qayed.tn/v1/establishment-links \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "link_code": "QY-7F3K2A"
  }'
```

Réponse (`201`) :

```json
{
  "establishment_id": "0f2e6b8a-1c3d-4e5f-9a0b-1c2d3e4f5a6b",
  "establishment_name": "Dar el Kenz",
  "linked_at": "2026-09-16T09:12:00+00:00"
}
```

Conservez `establishment_id` : il identifie l'établissement dans tous les
appels suivants. Vous pouvez à tout moment lister vos établissements liés
(avec leur quota courant) via :

```bash
curl https://qayed.tn/v1/establishments \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx"
```

> Cet appel de liaison est limité à **5 requêtes/minute** (anti brute-force du
> code) — ne le répétez pas en boucle en cas d'échec.

---

## 2. Créer une session de fiche

Pour chaque séjour (identifié par votre propre référence de réservation
`booking_ref`), créez une session :

```bash
curl -X POST https://qayed.tn/v1/fiche-sessions \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "establishment_id": "0f2e6b8a-1c3d-4e5f-9a0b-1c2d3e4f5a6b",
    "booking_ref": "DIAR-88213",
    "arrival_date": "2026-09-20",
    "departure_date": "2026-09-23",
    "room": "204",
    "guests": [
      { "first_name": "Amine", "last_name": "Trabelsi", "nationality": "TUN" }
    ],
    "metadata": { "pms_booking_id": "88213" }
  }'
```

Réponse (`201` pour une nouvelle session, `200` si vous rappelez avec le même
`(establishment_id, booking_ref)` — idempotent, pas de double fiche créée) :

```json
{
  "session_id": "5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e",
  "widget_url": "https://qayed.tn/widget/fiche?token=eyJ...",
  "expires_at": "2026-09-16T09:15:00+00:00",
  "mode": "create"
}
```

- **Chambres** : `room` est un texte libre, conservé pour affichage seulement — il
  ne rattache la fiche à aucune chambre réelle. Pour un vrai rattachement, récupérez
  la liste des chambres Qayed via `GET /v1/establishments/{id}/rooms`, construisez
  votre propre correspondance (vos libellés OTA → ces chambres), et envoyez
  `room_id` plutôt que — ou en plus de — `room`.
- **Éviter de rouvrir une fiche déjà soumise sans raison** : avant d'activer votre
  bouton « Fiche police », vous pouvez vérifier l'état d'une réservation via
  `GET /v1/fiche-sessions/by-booking-ref?establishment_id=...&booking_ref=...`
  (utile après un redémarrage de votre intégration, quand vous n'avez plus le
  `session_id` d'origine). Note : rouvrir une fiche déjà soumise n'est de toute
  façon jamais dangereux côté Qayed — cela ouvre le widget en mode `amend` pour
  corriger/compléter, sans jamais créer de doublon.
- `guests` peut être un tableau **vide** : le réceptionniste saisira tout dans
  le widget.
- Le champ `mode` vaut `amend` (au lieu de `create`) si une fiche existe déjà
  pour ce séjour — le widget permettra alors de la compléter/corriger sans en
  créer une seconde.
- `widget_url` porte un jeton **valable 15 minutes et à usage unique** — ne le
  générez qu'au moment où vous êtes prêt à l'ouvrir côté client.
- Le quota de fiches n'est **jamais bloquant** sur cet appel ; seul un accès
  API désactivé sur le plan de l'établissement (`api_access_disabled`, HTTP
  403) empêche la création.

---

## 3. Ouvrir le widget

Ouvrez `widget_url` dans une iframe côté client — jamais en redirection
plein-onglet, et jamais en générant vous-même l'URL depuis le navigateur (la
clé API ne doit jamais transiter côté client, l'appel de l'étape 2 doit rester
server-to-server).

Voir [`widget-integration.html`](/docs/snippets/widget-integration.html) pour
un exemple complet en HTML/JS vanilla : modale plein écran,
`allow="camera"` sur l'iframe (indispensable pour la capture caméra intégrée
CIN/passeport), et gestion des trois événements `postMessage` envoyés par le
widget à la fenêtre parente (toujours vérifier `event.origin`) :

| Événement | Payload | Sens |
|---|---|---|
| `qayed:submitted` | `{ session_id, fiche_id, guest_count }` | Fiche soumise avec succès. |
| `qayed:closed` | `{ session_id }` | L'utilisateur a fermé le widget sans soumettre. |
| `qayed:error` | `{ code }` | Erreur bloquante (voir les codes d'erreur dans `openapi.yaml`). |

Vous pouvez suivre l'état d'une session côté serveur à tout moment, en
complément des événements `postMessage` (qui ne sont qu'un signal UX
immédiat, pas la source de vérité) :

```bash
curl https://qayed.tn/v1/fiche-sessions/5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx"
```

---

## 4. Recevoir le webhook de résultat

Configurez un endpoint webhook depuis votre espace partenaire Qayed (URL +
secret généré une seule fois). Vous recevrez un `POST` signé à chaque
événement :

- `fiche.submitted` — fiche complétée et soumise (voir `guest_count`,
  `metadata` renvoyé tel quel depuis l'étape 2).
- `fiche.failed` — la soumission a échoué côté widget.
- `session.expired` — le lien du widget a expiré sans soumission.

En-têtes reçus :

```
Qayed-Event: fiche.submitted
Qayed-Signature: t=1758000000,v1=3f7a1c9e...
Content-Type: application/json
```

`Qayed-Signature` se vérifie ainsi : `v1` doit être égal à
`HMAC-SHA256("{t}.{corps_brut}", secret_de_votre_endpoint)`, avec `t` dans une
fenêtre de tolérance de 300 secondes autour de l'heure courante. Utilisez
directement l'un des deux exemples fournis, qui implémentent cette
vérification (y compris la tolérance anti-rejeu) :

- Node.js : [`examples/verify-webhook.js`](./examples/verify-webhook.js)
- PHP : [`examples/verify-webhook.php`](./examples/verify-webhook.php)

Exemple de corps reçu pour `fiche.submitted` :

```json
{
  "fiche_id": "a1b2c3d4-e5f6-4789-90ab-cdef01234567",
  "session_id": "5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e",
  "booking_ref": "DIAR-88213",
  "establishment_id": "0f2e6b8a-1c3d-4e5f-9a0b-1c2d3e4f5a6b",
  "guest_count": 1,
  "submitted_at": "2026-09-16T09:14:32+00:00",
  "metadata": { "pms_booking_id": "88213" }
}
```

Si votre endpoint ne répond pas `2xx`, Qayed retente avec un backoff
exponentiel (1, 5, 15, 60, 240, puis 1440 minutes — au moins 5 tentatives sur
24h) avant d'abandonner. Un endpoint désactivé après trop d'échecs consécutifs
reste réactivable depuis l'espace partenaire.

---

## Aller plus loin

- Référence complète de tous les endpoints, schémas et codes d'erreur :
  [`openapi.yaml`](./openapi.yaml).
- Script de QA manuelle pour valider une intégration widget de bout en bout
  (CSP, caméra, responsive, mode test) : [`MANUAL-QA-WIDGET.md`](./MANUAL-QA-WIDGET.md).
- Limite de débit : 120 requêtes/minute par clé API sur `/v1/*` (en-têtes
  `X-RateLimit-*` sur chaque réponse), 5/minute sur l'échange de code de
  liaison, 30/minute sur les appels du widget.
