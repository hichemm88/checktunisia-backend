# API partenaire Qayed — V1.2 : rapport

Date : 23/11/2026. Fait suite à la V1.1 (en production depuis le 22/11/2026) et au premier
test réel de Hichem (Android, Chrome, depuis Diarna, arrivée Dar Dou du 18/04/2027, clé de test).

## 1. Cause de l'écran « liste » vu par Hichem

**Ce n'était pas le widget V1.1, c'était l'ancien bundle du 16/09, resservi par le cache de Chrome.**

Preuves (données de production, lecture seule) :

| Heure (UTC) | Événement |
|---|---|
| 22:19:13 | Diarna crée la session de test Dar Dou (brouillon créé par l'utilisateur d'intégration). Le téléphone ouvre le widget à 22:19:40, **avant** la publication de la V1.1. Il charge donc `widget-main.js` du 16/09. Aucun voyageur n'est ajouté. |
| 22:23:25 | Publication de la V1.1 (nouveau `widget-main.js`, même nom de fichier). |
| 22:47:07 | Nouvelle demande de widget pour le même `booking_ref` : nouvelle session rattachée **au même brouillon** (reprise). |
| 22:47:55 | Scan du document depuis Android Chrome 157. |
| 22:48:29 | Voyageur ajouté. |
| 22:52:57 | Fiche soumise, 4 min 28 s plus tard. |

- Les libellés décrits par Hichem (« Voyageurs », « Ajouter un voyageur », petit bouton « Scanner un
  document », « Soumettre la fiche », référence de réservation affichée) n'existent **que** dans le
  bundle du 16/09 (commit 73b091f). Aucun ne figure dans le bundle V1.1.
- Le bundle était servi sous un nom **fixe** (`/widget-assets/widget-main.js`), sans `Cache-Control`
  ni version dans l'URL. Faute d'en-tête, Chrome applique une fraîcheur heuristique de 10 % de l'âge
  du fichier : le fichier du 16/09 avait environ 67 jours, soit **environ une semaine de cache sans
  revalidation**. Le téléphone, qui l'avait chargé à 22:19, l'a réutilisé à 22:47 sans redemander le
  nouveau.
- L'hypothèse « reprise d'un brouillon » est **en partie juste** : la session de 22:47 reprenait bien
  le brouillon de 22:19. Ce n'est pas elle qui a produit l'écran liste, mais elle a révélé un vrai
  défaut de la V1.1, corrigé aussi : en reprise d'un brouillon (mode `create`), le bootstrap ne
  renvoyait pas les voyageurs déjà saisis ; le widget aurait redemandé le voyageur 1.

Correctifs :
- `WidgetShellController` ajoute `?v=<empreinte du bundle>` aux URL du JS et du CSS : chaque nouveau
  bundle a une nouvelle URL, l'ancien cache devient inoffensif. La page porteuse du jeton est servie
  en `Cache-Control: no-store`.
- Le bootstrap renvoie `existing_guests` dans tous les modes, avec `removable`.

## 2. Attribution de la fiche de test du 22/11/2026

Fiche Dar Dou, arrivée du 18/04/2027 (check-in `01a20557…`, statut actif, 1 voyageur, chambre
« Chambre Turquoise », `test_mode = true`) :
- voyageur ajouté par **Hichem Mathlouthi** (compte Qayed réel, actif, non système) ;
- fiche finalisée (`completed_by`) par **Hichem Mathlouthi** ;
- brouillon initial créé par l'utilisateur d'intégration (normal : c'est Diarna qui l'a créé, avant
  toute ouverture du widget).

L'attribution est conforme. Cette fiche sera supprimée par la purge des données de test (lot 4).

## 3. Lot 1 — widget : ce qui a été réutilisé de l'application, ce qui a été supprimé

**Réutilisé (même code que l'écran Check-in de l'application)** : les blocs de `GuestScanPanel`
ont été sortis dans `src/components/hotel/GuestScanParts.tsx`, que l'application ET le widget
utilisent désormais :
- `ScanEntryCards` : les deux grandes cartes « Scanner la CIN tunisienne » / « Passeport ou CIN
  étrangère » et le lien « Saisir à la main » ;
- `ScanReading` : l'état de lecture (animation, « Lecture du document… ») ;
- `GuestFields` : les champs du voyageur, les pastilles de confiance, l'alerte d'expiration ;
- `CINCapture` (caméra intégrée), `guestFormBlockers` (champs requis) et `namePlausibility`
  (un nom qui ressemble à une légende imprimée est vidé et signalé) ;
- mêmes couleurs, typographie et libellés (clés i18n `cinScan.*`, `guestScan.*`, `common.*`).

**Supprimé** : `src/components/widget/GuestForm.tsx` (formulaire parallèle du widget, sexe en
« M/F/X », nationalité en code), et la logique de fusion propre au widget (`guestFromPrefill`,
`mergeExtracted`, `flowProgress`), remplacée par le formulaire plat de l'application.

**Parcours** : un seul, identique en création, reprise d'un brouillon et complément :
1. ouverture sur le scan du prochain voyageur, compteur « 2 sur 3 », voyageurs déjà saisis repliés
   au-dessus (ou directement la liste si tous sont déjà saisis) ;
2. dès la prise de vue, état de lecture, champs masqués ; au-delà de 15 s, « La lecture prend plus
   de temps que prévu » avec « Réessayer » (renvoie la même photo) et « Saisir à la main » ;
3. vérification des champs, bouton unique « Enregistrer ce voyageur » ;
4. liste des voyageurs, bouton unique « Valider la fiche », lien « Ajouter un voyageur ».

En-tête : maison, chambre, dates d'arrivée et de départ, voyageur principal ; plus aucun
identifiant technique. Sexe « Masculin / Féminin / Autre », nationalité et pays de délivrance par
nom (« Tunisie », « Royaume-Uni »). Plein cadre : marges du document à 0, hauteur de l'iframe.
Le libellé « Saisie manuelle » de l'application devient « Saisir à la main » (même composant des
deux côtés).

**Deux personnes sur la même fiche** : si quelqu'un a agi dans le widget depuis moins de 10 minutes
(`WIDGET_SESSION_LOCK_MINUTES`), la seconde personne voit « Fiche en cours de saisie par {nom} »
avec « Réessayer ». Sa demande n'efface plus le jeton de la première (qui pouvait jusqu'ici perdre
sa saisie) ; son lien n'est pas consommé et resservira. La même personne qui rouvre n'est jamais
bloquée ; sans aucune identité transmise, comportement V1.1 (reprise de la main). L'opérateur
d'une fiche devient celui qui ouvre réellement le widget (`requested_operator_*`).

**Correctifs annexes** : en complément d'une fiche transmise, seuls les voyageurs ajoutés par la
session peuvent être retirés (avant : tous, côté serveur) ; le bundle du widget n'embarque plus
toutes les traductions de l'application (`DirectionalIcon` importait `@/i18n`) : 328 Ko au lieu de
580 Ko.

## 4. Champs et routes ajoutés au contrat (OpenAPI 1.2.0, aucun retrait)

- `GET /v1/fiches?establishment_id=&arrival_from=&arrival_to=&updated_since=&limit=&cursor=` :
  `{data, next_cursor, has_more}`, 50 par défaut (1 à 100), tri par création décroissante.
  Champs : `fiche_id`, `establishment_id`, `booking_ref` (null si saisie dans Qayed), `room_id`,
  `room`, `arrival_date`, `departure_date`, `guest_count`, `primary_guest_name`, `status`, `source`
  (`api` | `qayed`), `operator_user_id`, `submitted_at`, `delivery_status`. Aucun numéro de document,
  aucune date de naissance, aucune image (vérifié par test). Brouillons exclus. Clé de test : fiches
  de test de ce partenaire uniquement ; clé live : fiches Qayed + fiches API live du partenaire,
  jamais les fiches de test ni celles d'un autre partenaire. Établissement non lié : 403
  `establishment_not_linked`.
- `delivery_status` (`sent` | `pending` | `failed` | `not_applicable`) dans `GET /v1/fiches/{id}`,
  la réponse du `PATCH`, la liste, `GET /v1/fiche-sessions/{id}` et `by-booking-ref`. Agrégation sur
  les envois de la fiche : `failed` si un envoi a échoué définitivement ou a été annulé, sinon
  `pending` si un envoi n'est pas parti, sinon `sent` (« accepté par le canal », pas un accusé de
  l'autorité). `not_applicable` : mode test, brouillon, ou aucun envoi possible (module inactif,
  séjour annulé).
- Webhooks `fiche.delivered` et `fiche.delivery_failed` (mode live, fiches créées par l'API, un seul
  événement par fiche et par issue), payload sans donnée personnelle (`fiche_id`, `session_id`,
  `booking_ref`, `establishment_id`, `delivery_status`, `occurred_at`, `reason` générique).
  **Les endpoints webhook existants ne les reçoivent qu'après ajout des événements à leur liste**
  (les nouveaux endpoints y sont abonnés par défaut) : à faire pour Diarna.
- Widget (interne, hors contrat partenaire) : `locked_by` et `existing_guests[].removable` dans le
  bootstrap.

## 5. Lot 4 — purge des données de test (non lancée)

- Commande : `php artisan partner:purge-test-data {partner} {--dry-run} {--force}` (UUID ou slug).
- Admin → Partenaires API → détail → « Purger les données de test » : aperçu des nombres par type,
  confirmation en recopiant le total ; l'exécution exige le jeton de l'aperçu (refus 409 si les
  données ont changé). Endpoint `POST /api/v1/admin/partners/{id}/purge-test-data`
  (`platform_admin`, 2FA ; 401 sans authentification, vérifié en production). Audit
  `partner.test_data_purged` (comptes seulement).
- Supprime définitivement : sessions de test, check-ins de test (brouillons, fiches, séjours),
  voyageurs rattachés uniquement à eux, scans et images (base et disque), envois en file,
  notifications, livraisons webhook de test. Les chambres sont libérées.
- Ne touche jamais : fiches live, fiches saisies dans Qayed, check-in mixte (visé aussi par une
  session live ou d'un autre partenaire), fiche ayant consommé du quota ou eu un envoi réel, fiche
  ou voyageur sous alerte watchlist, voyageur rattaché à un autre check-in.
- **À lancer par Hichem avant d'émettre la clé live.** La fiche de test du 22/11 (Dar Dou,
  18/04/2027) fait partie du périmètre.

## 6. Lot 5 — garde-fous

- **Constat** : une facture impayée depuis 21 jours suspendait automatiquement l'abonnement
  (`BillingService::suspendForNonPayment`), et un abonnement reconduit impayé expirait après la
  période de grâce (`SuspendExpiredSubscriptions`), y compris pour un compte dont l'accès API est
  actif. Conséquence : l'API refusait toute nouvelle fiche (`api_access_disabled`). Seuls les
  comptes internes étaient déjà exclus.
- **Désormais** : « accès API actif » = le pack (ou l'override client) accorde `api_access` ET au
  moins un établissement a une liaison active avec un partenaire actif. Pour un tel compte, la
  suspension et l'expiration automatiques sont retenues (garde dans le service, `ApiAccessGuard`),
  une alerte part aux administrateurs (une par facture ou par échéance) et un audit
  `subscription.auto_suspension_withheld` est écrit. Les relances au client continuent. Fin
  d'essai et résiliation choisie par le client restent effectives.
- **Alerte J-15** : `subscriptions:alert-api-expiring`, tous les jours à 08:05, abonnements actifs
  ou en essai à accès API actif, comptes internes compris, une seule alerte par échéance.

## 7. Vérifications

| Vérification | Résultat |
|---|---|
| Tests du périmètre (API partenaire, widget, purge, garde-fous, facturation, abonnements, relais) | 357 tests, 1867 assertions, OK |
| Suite backend complète | voir plus bas |
| Frontend : typecheck, oxlint, 304 tests vitest, build | OK |
| Widget local (faux serveur) : création, reprise de brouillon, lecture, lecture > 15 s, liste, verrou | OK |
| Production : page widget `no-store`, bundle `?v=b73f26c0dc42` à jour, OpenAPI 1.2.0, `/docs/api` 1.2.0 | OK |
| Production, session de test Dar Dou : ouverture sur le scan, en-tête, lecture visible, repli en saisie | OK |
| Production : verrou « en cours de saisie par Verification » pour une seconde personne | OK |
| Production : `GET /v1/fiches` clé test (1 fiche de test) et clé live (fiches Qayed, `delivery_status: sent`, aucun champ sensible) | OK |
| Session de vérification et son brouillon | supprimés |

## 8. Ce qui n'a pas pu être vérifié

**Pas d'appareil réel ni de caméra réelle ici.** Les vérifications visuelles viennent d'un
navigateur de bureau en format mobile, avec une image importée. À jouer par Hichem, depuis Diarna,
sur Chrome Android ET Safari iPhone, avec de vrais documents :

1. **Création** : nouvelle arrivée avec 2 voyageurs attendus. Attendu : ouverture directe sur
   « Voyageur 1 », compteur « 1 sur 2 », en-tête maison/chambre/dates/voyageur principal sans
   référence technique. Scanner une CIN : « Lecture du document… » visible pendant toute la lecture,
   puis champs remplis (Masculin/Féminin, « Tunisie »). « Enregistrer ce voyageur » → « Voyageur 2 »,
   le premier replié au-dessus. Scanner un passeport, enregistrer → liste → « Valider la fiche ».
2. **Reprise d'un brouillon** : nouvelle arrivée, enregistrer 1 voyageur sur 2, fermer la fenêtre
   Diarna sans valider, rouvrir « Fiche police ». Attendu : ouverture sur « Voyageur 2 sur 2 », le
   voyageur 1 déjà affiché, rien à ressaisir.
3. **Complément** : sur une fiche déjà validée, rouvrir « Fiche police ». Attendu : bandeau « vous
   ajoutez des voyageurs », compteur « N+1 », impossible de retirer un voyageur déjà déclaré.
   En plus : ouvrir la même fiche sur un second téléphone avec un autre réceptionniste →
   « Fiche en cours de saisie par … ».

Autres points non vérifiés ou ouverts :
- Bouton admin « Purger les données de test » non cliqué en production (connexion admin 2FA
  requise, et la purge ne doit pas être lancée) ; couvert par les tests automatisés.
- Chez Diarna : le filtre d'origine postMessage (`api.qayed.tn`) et l'ajout des événements
  `fiche.delivered` / `fiche.delivery_failed` à l'endpoint webhook.
- Une clé de test peut toujours lire une fiche live par son identifiant (`GET /v1/fiches/{id}`),
  alors que la liste les sépare : laissé tel quel pour ne rien casser, à trancher.
- Une session live qui reprendrait un brouillon créé en mode test garderait `test_mode=true`
  (pas d'envoi à l'autorité). Sans objet si la purge est lancée avant la clé live, ce qui est prévu.
- Les entrées d'audit `guest.added` antérieures contiennent nom et prénom des voyageurs de test ;
  la purge ne réécrit pas le journal d'audit.
- Traductions anglaise et arabe des écrans de purge admin : seul le français existe (repli sur le
  français).
