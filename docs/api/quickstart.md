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
`session.expired` le sont) : pour valider votre intégration de bout en bout en
mode test, relisez le statut avec `GET /v1/fiche-sessions/{session_id}` ou
`GET /v1/fiche-sessions/by-booking-ref` (voir l'étape 3).

**Hôte** : toutes les URL de l'API et du widget sont sur
`https://api.qayed.tn`. N'utilisez pas `https://qayed.tn`, qui redirige (308)
vers le site vitrine `www.qayed.tn` et ne sert aucune route d'API.

---

## 1. Lier un établissement à votre compte partenaire

Le propriétaire de l'établissement génère un **code de liaison** (valable 24h,
usage unique) depuis son espace Qayed et vous le communique. Échangez-le
contre un lien durable :

```bash
curl -X POST https://api.qayed.tn/v1/establishment-links \
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
curl https://api.qayed.tn/v1/establishments \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx"
```

> Cet appel de liaison est limité à **5 requêtes/minute** (anti brute-force du
> code) — ne le répétez pas en boucle en cas d'échec.

---

## 2. Créer une session de fiche

Pour chaque séjour (identifié par votre propre référence de réservation
`booking_ref`), créez une session :

```bash
curl -X POST https://api.qayed.tn/v1/fiche-sessions \
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
    "metadata": { "pms_booking_id": "88213" },
    "operator": { "user_id": "7d3e2c1b-0a9f-4e8d-b7c6-5a4b3c2d1e0f", "name": "Sami" },
    "locale": "fr"
  }'
```

Réponse (`201` pour une nouvelle session, `200` si vous rappelez avec le même
`(establishment_id, booking_ref)` — idempotent, pas de double fiche créée) :

```json
{
  "session_id": "5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e",
  "widget_url": "https://api.qayed.tn/widget/fiche?token=eyJ...",
  "expires_at": "2026-09-16T09:15:00+00:00",
  "mode": "create",
  "operator_matched": true
}
```

- **Chambres** : `room` est un texte libre, conservé pour affichage seulement — il
  ne rattache la fiche à aucune chambre réelle. Pour un vrai rattachement, récupérez
  la liste des chambres Qayed via `GET /v1/establishments/{id}/rooms`, construisez
  votre propre correspondance (vos libellés OTA → ces chambres), et envoyez
  `room_id` plutôt que — ou en plus de — `room`. Un `room_id` dont la chambre a
  encore un séjour ouvert n'est jamais refusé : ce séjour est clos (à sa date de
  départ prévue si elle est passée, sinon à la date du jour) et la session est
  créée.
- **Opérateur** (optionnel) : `operator.user_id` désigne le réceptionniste Qayed
  qui fait l'enregistrement, choisi dans `GET /v1/operators` (utilisateurs actifs
  des hébergeurs de vos établissements liés : `id`, `name`, `role`, jamais
  d'e-mail ni de téléphone). Votre manager relie une fois pour toutes, à la main,
  vos comptes à ces utilisateurs. S'il est valide, actif et du même hébergeur que
  l'établissement, la fiche lui est attribuée exactement comme s'il l'avait saisie
  dans Qayed (historique, journal d'audit, statistiques) et `operator_matched`
  vaut `true`. Sinon (absent, inconnu, désactivé, autre hébergeur, mal formé) :
  jamais d'erreur, la fiche est attribuée à l'utilisateur d'intégration,
  `operator.name` est conservé dans le journal d'audit et `operator_matched` vaut
  `false`. Un compte désactivé entre la création de la session et la soumission
  retombe aussi sur l'utilisateur d'intégration.
- **Langue** (optionnel) : `locale` vaut `fr` (par défaut), `ar` ou `en`.
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
widget à la fenêtre parente :

| Événement | Payload | Sens |
|---|---|---|
| `qayed:submitted` | `{ type, session_id, fiche_id, guest_count }` | Fiche soumise avec succès. |
| `qayed:closed` | `{ type, session_id }` | L'utilisateur a fermé le widget sans soumettre. |
| `qayed:error` | `{ type, code }` | Erreur bloquante (voir les codes d'erreur dans `openapi.yaml`). |

Ces messages sont émis avec `targetOrigin` `'*'` : vérifiez toujours
`event.origin === 'https://api.qayed.tn'` avant de les lire (le plus sûr est de
dériver l'origine de `widget_url` : `new URL(widget_url).origin`).

Parcours du widget : il s'ouvre directement sur l'écran de scan du premier
voyageur, avec un bandeau pré-rempli (dates, chambre, noms), un seul bouton
principal par écran et un compteur « 2 sur 3 ». La validation partielle est
permise (« Valider avec 2 voyageurs sur 3 ») ; les voyageurs manquants se
complètent plus tard en recréant une session pour le même `booking_ref` (mode
`amend`, toujours la même fiche unique).

**Verrou de saisie (1.2.0).** Pendant qu'une personne saisit une fiche, une
autre personne qui l'ouvre voit « {nom} est en train de saisir cette fiche » et
un bouton « Reprendre la saisie » : la reprise lui transfère la fiche avec tout
ce qui est déjà saisi, et le premier écran affiche « Saisie reprise par {nom} ».
Le même compte n'est jamais bloqué par son propre verrou, quel que soit
l'appareil. Le verrou tombe après 2 minutes sans action dans le widget et se
lève dès la fermeture du widget. Si vous retirez l'iframe vous-même (fermeture
de votre modale), libérez-le explicitement :

```bash
curl -X DELETE https://api.qayed.tn/v1/fiche-sessions/5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e/lock \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx"
```

Réponse `204`, même si aucun verrou n'était posé.

Vous pouvez suivre l'état d'une session côté serveur à tout moment, en
complément des événements `postMessage` (qui ne sont qu'un signal UX
immédiat, pas la source de vérité) :

```bash
curl https://api.qayed.tn/v1/fiche-sessions/5b6c7d8e-9f0a-4b1c-8d2e-3f4a5b6c7d8e \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx"
```

La réponse porte `status`, `fiche_id`, `guest_count` (voyageurs effectivement
déclarés ; 0 tant que la fiche est un brouillon) et `expected_guest_count`
(voyageurs passés à la création, minimum 1), en mode test comme en live. En
mode test, où `fiche.submitted` n'est pas envoyé, c'est ce GET qui valide
votre intégration de bout en bout.

---

## 4. Recevoir le webhook de résultat

Configurez un endpoint webhook depuis votre espace partenaire Qayed (URL +
secret généré une seule fois). Vous recevrez un `POST` signé à chaque
événement :

- `fiche.submitted` — fiche complétée et soumise (voir `guest_count`,
  `metadata` renvoyé tel quel depuis l'étape 2).
- `fiche.failed` — la soumission a échoué côté widget.
- `session.expired` — le lien du widget a expiré sans soumission.
- `fiche.delivered` (1.2.0) — tous les envois de la fiche à l'autorité ont
  abouti (`delivery_status` = `sent`). Live uniquement, une seule fois par fiche.
- `fiche.delivery_failed` (1.2.0) — un envoi de la fiche à l'autorité est en
  échec définitif (`reason` : `transmission_failed` ou
  `transmission_cancelled`). Live uniquement, une seule fois par fiche.

Les deux événements 1.2.0 ne portent aucune donnée personnelle (`fiche_id`,
`session_id`, `booking_ref`, `establishment_id`, `delivery_status`,
`occurred_at`). Ils sont souscrits par défaut sur tout nouvel endpoint ; pour un
endpoint existant, demandez leur ajout à l'équipe Qayed.

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

## 5. Tenir le séjour à jour

Votre logiciel est la source de vérité des séjours créés via l'API. Pour une
prolongation, un départ anticipé ou un changement de chambre, sans rouvrir le
widget :

```bash
curl -X PATCH https://api.qayed.tn/v1/fiches/a1b2c3d4-e5f6-4789-90ab-cdef01234567 \
  -H "Authorization: Bearer qyd_test_xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{ "departure_date": "2026-09-25", "room_id": "3c1a9e7a-2b4d-4f6a-8c0e-1a2b3c4d5e6f" }'
```

- Champs acceptés (au moins un) : `departure_date`, `room_id` (chambre issue de
  `GET /v1/establishments/{id}/rooms`), `room` (libellé, peut être `null`).
- `422 validation_error` si le corps est vide, si `departure_date` précède la
  date d'arrivée ou si `room_id` est inconnu ; `404 fiche_not_found` pour une
  fiche non créée par vous.
- Un changement de chambre clôt tout séjour encore ouvert dans la chambre cible.
  Prolonger jusqu'à aujourd'hui ou plus tard un séjour déjà clos
  automatiquement le rouvre. Chaque modification est tracée dans le journal
  d'audit.
- La réponse est celle de `GET /v1/fiches/{fiche_id}`, qui renvoie désormais
  aussi `arrival_date`, `departure_date`, `checked_out_at` (date ou `null`),
  `room_id` et `stay_status` (`draft`, `active`, `completed` ou `cancelled`).

**Départs automatiques** : chaque jour à 05:30 (heure de Tunis), les séjours
créés via l'API dont la date de départ prévue est atteinte sont clos à cette
date, avec exactement les effets d'un départ saisi dans Qayed (journal d'audit,
notification au manager ; rien n'est envoyé à l'autorité, aucun quota). Les
séjours saisis dans Qayed ne sont pas concernés. L'occupation d'une chambre
étant dérivée des séjours ouverts, elle reste occupée si un autre séjour y est
ouvert. Une nouvelle fiche le jour J sur une chambre dont le séjour précédent se
termine le jour J ou avant n'est jamais refusée ni retardée. Conséquence : tenez
`departure_date` exacte avec `PATCH`.

---

## 6. Lister les fiches d'un établissement (1.2.0)

`GET /v1/fiches` renvoie les fiches validées d'un établissement lié, qu'elles
aient été créées par votre intégration (`source: api`) ou saisies directement
dans Qayed (`source: qayed`) — utile pour réconcilier votre registre :

```bash
curl "https://api.qayed.tn/v1/fiches?establishment_id=0f2e6b8a-1c3d-4e5f-9a0b-1c2d3e4f5a6b&arrival_from=2026-09-01&arrival_to=2026-09-30&limit=50" \
  -H "Authorization: Bearer qyd_live_xxxxxxxxxxxx"
```

- Réponse : `{ "data": [...], "next_cursor": "...", "has_more": true }`. Tant
  que `has_more` vaut `true`, rappelez la même URL avec `cursor=<next_cursor>`.
- Filtres : `arrival_from`, `arrival_to` (dates d'arrivée incluses) et
  `updated_since` (ISO 8601) pour une synchronisation incrémentale — une fiche
  dont seul l'envoi à l'autorité a progressé compte aussi comme modifiée.
- Chaque élément : `fiche_id`, `booking_ref` (null pour une saisie dans Qayed),
  `room_id`, `room`, `arrival_date`, `departure_date`, `guest_count`,
  `primary_guest_name`, `status`, `source`, `operator_user_id`,
  `submitted_at`, `delivery_status`. Jamais de numéro de document, de date de
  naissance ni d'image.
- Les brouillons ne sont pas listés. Une clé test ne liste que les fiches créées
  en mode test par votre intégration ; une clé live, les fiches réelles.
- `403 establishment_not_linked` pour un établissement non lié à votre clé.

`delivery_status` (également renvoyé par `GET /v1/fiches/{fiche_id}` et
`GET /v1/fiche-sessions/...`) vaut `sent`, `pending`, `failed` ou
`not_applicable` (mode test, brouillon, module d'envoi inactif). Pour une fiche
à plusieurs voyageurs : `failed` dès qu'un envoi est en échec définitif, sinon
`pending` tant qu'un envoi est en cours, sinon `sent`.

---

## 7. Rattacher une fiche saisie dans Qayed (1.2.0)

Quand la réception a déclaré un voyageur directement dans Qayed avant que la
réservation existe chez vous, la fiche apparaît dans `GET /v1/fiches` avec
`source: "qayed"` et `booking_ref: null`. Rattachez-la à votre réservation :

```bash
curl -X PATCH https://api.qayed.tn/v1/fiches/<fiche_id> \
  -H "Authorization: Bearer qyd_live_..." \
  -H "Content-Type: application/json" \
  -d '{"booking_ref": "RES-2026-0042"}'
```

Ensuite, `POST /v1/fiche-sessions` avec ce `booking_ref` rouvre cette fiche en
mode `amend` (complément), sans jamais en créer une seconde.

- `409 booking_ref_conflict` : une autre fiche du même établissement porte déjà
  ce `booking_ref`.
- `409 booking_ref_already_set` : la fiche a déjà une autre référence. Rejouer la
  même valeur est sans effet.
- `404 fiche_not_found` : brouillon, établissement non lié, fiche déjà rattachée
  à un autre partenaire, ou clé de test (les fiches réelles lui sont invisibles).

## Nouveautés 1.2.0

Ajouts uniquement, aucun changement incompatible :

- `GET /v1/fiches` : liste paginée par curseur des fiches d'un établissement
  lié (API et saisies dans Qayed), filtres de dates et `updated_since`.
- `delivery_status` sur les fiches et les sessions.
- Webhooks `fiche.delivered` et `fiche.delivery_failed` (live uniquement) ; un
  endpoint déjà déclaré doit y être abonné (réglage Qayed, par événement).
- `GET /v1/fiches/{fiche_id}` renvoie `guests` : prénom et nom de chaque voyageur
  déclaré, rien d'autre.
- `PATCH /v1/fiches/{fiche_id}` accepte `booking_ref` pour rattacher une fiche
  saisie dans Qayed (section 7).
- `DELETE /v1/fiche-sessions/{session_id}/lock` : libère le verrou de saisie
  quand vous fermez la modale du widget (section 3). Verrou de 2 minutes sans
  action, levé à la fermeture, reprise possible par un collègue.

---

## Nouveautés 1.1.0

Ajouts optionnels uniquement, aucun changement incompatible :

- Hôte corrigé dans la documentation : `https://api.qayed.tn` pour l'API,
  `widget_url` et l'origine des messages `postMessage`.
- `GET /v1/operators` : utilisateurs des hébergeurs liés (`id`, `name`,
  `role`), pour relier vos comptes aux comptes Qayed.
- `POST /v1/fiche-sessions` : champs optionnels `operator` et `locale`,
  réponse `operator_matched` ; un `room_id` dont la chambre est occupée n'est
  plus jamais refusé.
- `GET /v1/fiche-sessions/{session_id}` et `/by-booking-ref` : `guest_count` et
  `expected_guest_count`, en test comme en live.
- `PATCH /v1/fiches/{fiche_id}` (prolongation, départ anticipé, changement de
  chambre) ; `GET /v1/fiches/{fiche_id}` renvoie le séjour.
- Départs automatiques quotidiens (05:30, heure de Tunis) des séjours créés via
  l'API.
- Widget : ouverture directe sur le scan, compteur de voyageurs, validation
  partielle complétée plus tard en mode `amend`, messages `postMessage`
  documentés dans la spec.

---

## Aller plus loin

- Référence complète de tous les endpoints, schémas et codes d'erreur :
  [`openapi.yaml`](./openapi.yaml).
- Script de QA manuelle pour valider une intégration widget de bout en bout
  (CSP, caméra, responsive, mode test) : [`MANUAL-QA-WIDGET.md`](./MANUAL-QA-WIDGET.md).
- Limite de débit : 120 requêtes/minute par clé API sur `/v1/*` (en-têtes
  `X-RateLimit-*` sur chaque réponse), 5/minute sur l'échange de code de
  liaison, 30/minute sur les appels du widget.
