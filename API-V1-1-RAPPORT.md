# API v1.1 — Rapport de livraison

Date : 22/11/2026. Dépôts : backend `checktunisia-backend`, frontend `checktunisia`,
branche `feat/api-v1-1` des deux côtés, partie d'`origin/main`. Diagnostic détaillé :
`API-V1-1-DIAGNOSTIC.md`.

## 1. Pourquoi 11 sessions et 0 fiche

Les 11 sessions ont toutes été créées le 16/09/2026, jour de la mise en ligne, en mode
test, pendant l'intégration. Il n'y en a eu aucune depuis.

- **Sessions 1 à 4** : la page du widget répondait 500. Le correctif est arrivé à 18:15,
  après elles.
- **Sessions 5 à 9** : tout ajout de voyageur était refusé en 403, à cause du contrôle
  d'origine. Corrigé à 20:19.
- **Sessions 10 et 11** : le widget s'est ouvert, puis plus rien. À 22:56, Diarna a masqué
  le bouton « Fiche police » (aucune maison liée, identifiants en attente).
- **Depuis** : le bouton est gelé derrière `QAYED_UI_ENABLED`. Personne n'a eu de bouton
  à presser.

Rien ne bloque côté API aujourd'hui. Trois défauts découverts en route auraient gêné la
première vraie fiche :

- **Bundle du widget jamais republié** : le scan et les messages d'erreur du 16/09 ne
  tournaient pas en production.
- **Hôte faux dans la documentation** : la doc annonçait `qayed.tn` au lieu de
  `api.qayed.tn`, et Diarna filtre les messages sur une origine fausse.
- **Statut invisible en mode test** : impossible de voir une fiche « soumise » sans webhook.

Les trois sont corrigés.

**À changer côté Diarna**, détaillé dans le diagnostic :

1. Origine `postMessage` : `https://api.qayed.tn`.
2. Réactiver le bouton.
3. Relire le statut à la fermeture du widget.
4. Utiliser `GET /v1/operators` et `operator.user_id`.
5. Tenir `departure_date` et la chambre à jour avec `PATCH /v1/fiches/{id}`.

## 2. Ce qui a été modifié

### Backend

**Opérateur**

- `GET /v1/operators` renvoie `id`, `name` et `role`. L'utilisateur d'intégration et les
  comptes inactifs en sont exclus.
- `operator.user_id` accepté si l'utilisateur est actif et appartient au même hébergeur.
  Il est revérifié à chaque appel du widget.
- **Attribution** : la fiche porte `created_by` et `completed_by` de l'opérateur, et le
  journal d'audit lui attribue l'ajout des voyageurs et la validation.
- **Repli** : dans tous les autres cas, c'est l'utilisateur d'intégration, et
  `operator.name` est conservé dans l'audit (`check_in.partner_operator`). Jamais d'erreur.

**Statut lisible sans webhook**

- `guest_count` et `expected_guest_count` sur les deux `GET` de session, en mode test
  comme en live.

**Widget**

- `locale` (fr, ar, en) transmise au widget.
- `bootstrap` renvoie en plus `expected_guest_count`, `locale`, des dates `AAAA-MM-JJ` et
  la chambre Qayed réellement rattachée.
- `POST /widget/v1/submit` accepte des dates corrigées, appliquées seulement à un
  brouillon.

**Chambres et départs**

- Une chambre occupée n'est plus jamais un refus. Le séjour encore ouvert dans la chambre
  est clos (à sa date de départ prévue si elle est passée, sinon aujourd'hui), puis la
  session est créée.
- `PATCH /v1/fiches/{fiche_id}` accepte `departure_date`, `room_id` et `room`. Usages :
  - **Prolongation** : rouvre un séjour clos automatiquement si la nouvelle date est
    aujourd'hui ou plus tard.
  - **Départ anticipé.**
  - **Changement de chambre** : clôt le séjour ouvert dans la chambre cible.
  - Refuse un départ antérieur à l'arrivée. Action tracée dans l'audit.
  - `GET` et `PATCH /v1/fiches/{id}` renvoient en plus `arrival_date`, `departure_date`,
    `checked_out_at`, `room_id` et `stay_status`.
- **Départ automatique** : `partner-stays:auto-checkout`, chaque jour à 05:30, heure de
  Tunis.
  - Il clôt les séjours actifs issus de l'API dont le départ prévu est atteint, à cette
    date.
  - Il passe par le même `checkout()` que le départ saisi dans Qayed : audit et
    notification aux managers, rien vers l'autorité, ni relais ni quota.
  - Les fiches saisies dans Qayed ne sont pas concernées.
  - L'occupation d'une chambre est dérivée des séjours ouverts : une chambre reste occupée
    tant qu'un autre séjour y est ouvert.

**Administration et page du widget**

- `GET /api/v1/admin/partners/{id}/linkable-hotels?q=` et
  `POST /api/v1/admin/partners/{id}/links` : liaison directe, tracée dans l'audit
  (`partner.link_created`). Le code de liaison reste disponible pour les partenaires
  externes.
- La page du widget ne porte plus `X-Frame-Options: DENY`, qui contredisait sa CSP
  `frame-ancestors`.

### Frontend

**Widget « scanner et valider »**

- Ouverture directe sur le scan du premier voyageur attendu.
- **Bandeau** : dates (lien « Modifier »), chambre, noms et compteur « 2 sur 3 ».
- Un seul bouton plein par écran, et « Saisir à la main » pour un document illisible.
- **Validation partielle** : « Valider avec 2 voyageurs sur 3 ».
- **Langues** : fr, ar (RTL) et en. Le bundle n'embarque plus que les sections utiles des
  langues (304 kB au lieu de 550 kB).
- **Mobile** : zone sûre respectée, et barre basse qui suit `visualViewport` pour que le
  clavier ne masque pas le bouton.

**Administration et documentation**

- Admin → Partenaires API : bouton « Lier un établissement », avec recherche par nom.
- `/docs/api` : hôte `api.qayed.tn`, nouveautés 1.1.0, messages `postMessage`, départs
  automatiques, validation en mode test.

**Documentation backend** : `openapi.yaml` en 1.1.0, `quickstart.md` et
`widget-integration.html` (origine `https://api.qayed.tn`, dérivée de `widget_url`).

## 3. Ce qui a été supprimé

- Le refus « chambre déjà occupée » de l'API et son test (`RoomAssignmentTest`).
- L'ancien formulaire du widget : liste, puis « Ajouter un voyageur », puis formulaire,
  puis « Valider ».
- Neuf clés de traduction du widget devenues inutiles.

## 4. Écarts avec le prompt

- **Champ `name` des chambres** : non ajouté. Une chambre Qayed n'a pas de libellé
  distinct de `number`, qui est celui affiché dans Qayed : le partenaire fait sa
  correspondance sur `number`.
- **Opérateur** : remplacé, à la demande, par `GET /v1/operators` et `operator.user_id`.
  Aucune correspondance par e-mail ni par téléphone n'a été écrite.
- **Hôte du widget** : aucune redirection à supprimer. `widget_url` était déjà construit
  sur `APP_URL`, qui vaut `https://api.qayed.tn` en production. Seules la doc et les
  exemples étaient faux.
- **Chambre modifiable dans le widget** : non. Seules les dates sont modifiables
  (« Modifier »). La chambre se change par `PATCH /v1/fiches/{id}`, depuis le PMS.
- **INPDP, image de la pièce** : l'image scannée reste conservée comme pour toute fiche
  native, car elle est jointe à la fiche de police transmise. Elle est ensuite supprimée
  par la tâche horaire existante `whatsapp:purge-images`, au-delà de 24 h par défaut. Ce lot n'ajoute aucune persistance, et
  aucune donnée personnelle ne figure dans les URL ni dans les journaux.
- **Départ automatique** : il ne clôt que les séjours actifs. Un brouillon jamais validé
  reste ouvert, mais ne bloque jamais une nouvelle fiche API sur sa chambre (filet de
  sécurité).
- **Rejeu à 360 px** : fait dans Chromium et WebKit (Playwright), dans une iframe
  cross-origin `allow="camera"`, contre un faux backend. La caméra réelle n'est pas
  testable ici : le scan passe par l'import d'image. Un essai sur iPhone et Android réels
  reste à faire.

## 5. Champs ajoutés au contrat (pour le prompt Diarna)

Tous facultatifs ou nouveaux. Aucun champ existant n'est modifié ni retiré.

**`GET /v1/operators`** (nouveau)

- Réponse : `data[]` de `{ id: uuid, name: string, role: string }`.

**`POST /v1/fiche-sessions`**

- Requête : `operator?: { user_id?: string, name?: string }` et
  `locale?: "fr" | "ar" | "en"` (défaut `fr`).
- Réponse : `operator_matched: boolean`.
- Comportement : un `room_id` dont la chambre a un séjour ouvert n'est plus refusé ; le
  séjour précédent est clos.

**`GET /v1/fiche-sessions/{session_id}` et `GET /v1/fiche-sessions/by-booking-ref`**

- Réponse : `guest_count: integer` (voyageurs déclarés, 0 tant que la fiche est en
  brouillon) et `expected_guest_count: integer` (au moins 1).

**`PATCH /v1/fiches/{fiche_id}`** (nouveau)

- Requête : `departure_date?: date`, `room_id?: uuid`, `room?: string | null`.
- Réponse : identique à `GET /v1/fiches/{fiche_id}`.
- Erreurs :
  - 422 `validation_error` : corps vide, départ avant l'arrivée, `room_id` inconnu,
    fiche annulée, ou prolongation d'un séjour clos dont la chambre a été réattribuée
    sans `room_id`.
  - 404 `fiche_not_found` et 403 `establishment_not_linked`.

**`GET /v1/fiches/{fiche_id}`**

- Réponse en plus : `arrival_date`, `departure_date`, `checked_out_at` (date ou null),
  `room_id` et `stay_status` (`draft`, `active`, `completed` ou `cancelled`).

**Messages `postMessage` du widget**

- Origine : `https://api.qayed.tn`.
- `qayed:submitted` : `{ type, session_id, fiche_id, guest_count }`.
- `qayed:closed` : `{ type, session_id }`.
- `qayed:error` : `{ type, code }`.

**Comportements**

- Aucun webhook `fiche.submitted` en mode test : relire le statut.
- Départ automatique à 05:30 (heure de Tunis) pour les séjours API.
- Complément d'une fiche partielle : recréer une session pour le même `booking_ref`
  (mode amend, une seule fiche).
