# Lot de nuit du 23/11/2026 — rapport

Tout est déployé en production (backend 09a393e, frontend 0907472 sur `main`) et vérifié en ligne
avec la clé de test uniquement. Aucun test n'a été fait avec la clé live ni sur une fiche réelle.

## 1. Webhook Diarna

- L'endpoint `https://diarna.kasbahost.com/api/webhooks/qayed` (actif, 0 échec) est abonné à
  `fiche.delivered` et `fiche.delivery_failed`, en plus de `fiche.submitted`, `fiche.failed` et
  `session.expired`. Changement tracé dans le journal d'audit (`partner.webhook_events_changed`).
- Admin > Partenaires API > détail > Webhooks : une case à cocher par événement sur chaque
  endpoint. Le backend refuse désormais un événement inconnu (422) et trace chaque changement.

## 2. Rattacher une fiche saisie dans Qayed à une réservation partenaire

- `PATCH /v1/fiches/{fiche_id}` accepte `booking_ref` sur une fiche saisie dans Qayed qui n'a pas
  de référence. La fiche garde `source: qayed`, devient lisible par le partenaire, et
  `POST /v1/fiche-sessions` avec ce `booking_ref` la rouvre en complément (`amend`) au lieu d'en
  créer une seconde.
- Refus : `409 booking_ref_conflict` si une autre fiche du même établissement porte déjà ce
  `booking_ref` ; `409 booking_ref_already_set` si la fiche a déjà une autre référence (rejouer la
  même valeur ne change rien) ; `404` pour un brouillon, un établissement non lié, une fiche déjà
  rattachée à un autre partenaire, ou avec une clé de test.
- Les rattachements et créations concurrents sur un même couple établissement + `booking_ref` sont
  sérialisés.

## 2 bis. `guests` dans `GET /v1/fiches/{fiche_id}`

Un tableau `guests`, voyageur principal en tête, avec `first_name` et `last_name` uniquement.
Aucun document, aucune date de naissance, aucune image (vérifié par test et en production). La
réponse du `PATCH` reprend le même contenu.

## 3. Doublons possibles (lecture seule, rien supprimé ni fusionné)

Établissements liés à Diarna : Dar Zyne, Oukela Sidi Mefredj, Dar Majoul, Dar Dou, Dar Kenza May.
Critère : deux fiches non brouillon, hors test, même chambre, séjours qui se chevauchent, sur les
60 derniers jours. Aucune fiche sans chambre sur la période, donc rien d'invérifiable.

| Établissement | Chambre | Fiche 1 | Fiche 2 |
|---|---|---|---|
| Dar Majoul | Chambre verte | QYD-20261105-0001 : 05/11 au 11/11, 1 voyageur, saisie dans Qayed | QYD-20261111-0001 : 10/11 au 13/11, 2 voyageurs, saisie dans Qayed |

Les autres établissements : aucun chevauchement. Pour Dar Majoul, le chevauchement porte sur la
nuit du 10 au 11/11 et les deux fiches ont un nombre de voyageurs différent : c'est plus
probablement un départ du 10/11 non enregistré sur la première fiche qu'un vrai doublon. À vérifier
avec la maison.

## 4. Tests

- Suite backend complète (Docker, 131 fichiers listés explicitement), état V1.2 :
  **1298 tests, 5139 assertions, 0 échec.**
- Après le lot de nuit : tests API partenaire et admin partenaires, **111 tests, 912 assertions,
  OK**, puis fichiers modifiés relancés (`FicheBookingRefAttachTest` 8, `FicheListTest` 8,
  `FicheDeliveryStatusTest` 7, `PartnerTestDataPurgeTest` 8) : OK. La suite complète n'a pas été
  relancée après ces derniers changements, qui ne touchent que l'API partenaire.
- Frontend : typecheck, oxlint, tests vitest des écrans admin, build : OK.

## 5. Vérifié en production (clé de test)

Une fiche de test fictive à 2 voyageurs créée sur Dar Dou, puis :

- `GET /v1/fiches/{id}` : `guests` = 2 voyageurs, prénom et nom seuls, aucun numéro de document ;
- `PATCH booking_ref` avec la même référence : 200 sans changement ; avec une autre : 409 ;
- `PATCH booking_ref` sur une fiche réelle avec la clé de test : 404, fiche réelle inchangée ;
- nouvelle demande de session pour la même référence : mode `amend`, même fiche.

Puis suppression de cette fiche, de ses sessions et de ses 2 voyageurs fictifs : 0 restant.
Constat au passage : la fiche de test du 22/11 n'existait plus, la purge des données de test a donc
été lancée avant la clé live.

## 6. Non vérifié

- **Aucun test sur appareil réel** (Chrome Android, Safari iPhone, caméra réelle) : pas d'appareil
  ici. Les trois parcours à jouer (création, reprise d'un brouillon, complément) sont détaillés
  dans `API-V1-2-RAPPORT.md`, section 8.
- Les cases à cocher de l'admin n'ont pas été cliquées en production (connexion admin 2FA) ;
  l'abonnement Diarna a été posé directement, avec la même trace d'audit.
- Réception effective par Diarna d'un `fiche.delivered` / `fiche.delivery_failed` : arrivera au
  premier envoi réel à l'autorité d'une fiche créée par l'API. Le journal de livraison est visible
  dans l'admin.
