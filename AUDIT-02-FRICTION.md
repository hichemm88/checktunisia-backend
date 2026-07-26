# AUDIT 02 — Simplicite & Vitesse (coeur de la mission)

> **Skills mobilises : `code-review` + `debug`** (non installes dans cet environnement ; methodologie appliquee manuellement via 6 agents d'exploration a preuve, chaque constat cite en `fichier:ligne`).
> Objectif : rendre chaque manipulation existante plus simple et plus rapide. Rien de nouveau n'est ajoute au parcours ; on soustrait de la friction.

## A. Verification des 6 regles d'or

| # | Regle d'or | Statut | Preuve |
|---|-----------|--------|--------|
| 1 | Tache quotidienne ≤ 3 taps depuis l'ouverture | **NON RESPECTE** | Check-in = 8 taps / ~3 ecrans + overlay camera + 3 sous-etapes (`CheckInWizardPage.tsx`, `GuestScanPanel.tsx`). Login n'amene pas la receptionniste directement au wizard (interstitiel dashboard). |
| 2 | Zero saisie redondante (donnees connues pre-remplies) | **PARTIEL** | Dates (today/+1), adultes (1) pre-remplis. MAIS : chambre obligatoire sans defaut ; **prefill client recurrent = code mort non cable** (`cin.ts:139-166` vs absence de route hotel de lookup CIN dans `routes/api.php`) ; multi-voyageurs sans report d'infos. |
| 3 | Acces multi-proprietes jamais conditionne par le role | **RESPECTE** | `MyPropertiesController::index` sans check de role (« for both roles ») ; `ResolveTenant` gate sur `isHotelStaff()` (les deux roles), header `X-Property-Id` traite a l'identique ; deep-link non autorise ignore cote serveur. Seule la *gestion* CRUD des proprietes reste `hotel_admin` (administration, pas acces). |
| 4 | Aucun emoji dans notifications/communications sortantes | **VIOLE (mineur)** | `ReportNextCheckin.php:92-93` : `✅` et `⚠️` dans le corps d'un email envoye au gerant. Landing marketing : `📷` (`src/cms/mockups.ts:150,154`), `★★★★★` (`src/cms/blocks.tsx:343`). Fiches WhatsApp/emails transactionnels : propres. |
| 5 | Offline-first pour les flux terrain | **NON RESPECTE** | `public/sw.js` volontairement sans cache (passthrough), aucun IndexedDB / file offline / background-sync. La fiche ne peut pas etre creee hors reseau. |
| 6 | Respect strict de la charte | **RESPECTE (a 90%)** | Palette exacte, sceau قيد a −6°, polices IBM Plex correctes ; « Archivo » variable @900 au lieu de la famille « Archivo Black » (equivalent fonctionnel) ; rouges/gris hors-charte codes en dur faute de token danger/neutre dans la palette. |

**Anomalie reproductible (skill `debug`)** : sur un scan CIN propre ou le sexe n'est pas inferable (filiation sans بن/بنت, pas d'epoux), le front laisse passer (`GuestScanPanel.tsx:665` ne verifie pas `sex`) mais le back exige `sex in:M,F,X` (`GuestController.php:28`) → **422 mute** avec toast generique sur un scan par ailleurs parfait. Bug quotidien, correctif Quick Win.

## B. Metriques par role x tache

Legende correctif : **QW** = Quick Win (< 1 j) · **MOY** = Moyen (< 1 sem) · **STR** = Structurel.

### B.1 Receptionniste

| Tache | Taps actuels | Ecrans | Champs manuels | Temps estime | Cible | Friction principale | Correctif (classe) |
|-------|-------------:|-------:|---------------:|-------------:|------:|---------------------|---------------------|
| Se connecter → wizard check-in | 3 saisies + 2 taps | 2 (login → dashboard → wizard) | email + mdp | ~30 s | 1 tap post-login | Interstitiel dashboard ; pas de deep-link vers le wizard | **QW** : rediriger la receptionniste vers `/hotel/check-ins/new` apres login (`LoginPage.tsx:48`), ou raccourci nav persistant |
| Creer un check-in (1 voyageur, CIN propre) | **8 taps** | 3 + overlay + 3 sous-etapes | 0 (scan) sauf `sex` parfois | ~60-90 s | **≤ 3 taps** | Chambre obligatoire sans defaut ; etape « booking » ; double confirmation (photo puis « confirmer voyageur ») | **STR** : wizard « scan-first » (camera a l'ouverture), chambre pre-selectionnee (derniere/seule chambre libre), fusion des confirmations |
| Corriger un champ « low confidence » | +1 a +5 saisies | idem | champs blanchis par OCR | +20-60 s | minimiser | Champs vides forcent la re-saisie exacte de ce dont l'OCR doutait (`GuestScanPanel.tsx:272-278`) | **MOY** : afficher la valeur douteuse en gris (pre-remplie, editable) plutot que blanchir ; mettre le focus dessus |
| Ajouter un 2e voyageur (meme sejour) | ~7 taps | overlay + form | scan complet a nouveau | ~60 s | reutiliser sejour | Aucun report property/dates ; pas de prefill client recurrent | **MOY** (report sejour) + **STR** (cabler lookup CIN) |
| Voir/retrouver une fiche | 2-3 taps | history → detail | filtre texte | ~15 s | ok | RAS | — |
| Basculer de propriete | 2 taps | switcher | — | ~5 s | ok | Conforme regle 3 | — |

### B.2 Manager multi-proprietes (hotel_admin)

| Tache | Taps | Ecrans | Champs manuels | Temps | Cible | Friction | Correctif |
|-------|-----:|-------:|---------------:|------:|------:|----------|-----------|
| Onboarding initial | wizard 4 etapes + onboarding | 5+ | org, proprietes, chambres | ~5-8 min | < 5 min | Creation proprietes/chambres post-login separee du signup | **MOY** : import chambres en masse (existe `bulk`) mis en avant, gabarits |
| Ajouter un receptionniste | ~4 taps | settings/users | email, nom | ~30 s | ok | Invite + resend existants | — |
| Choisir les destinataires WhatsApp | ~3 taps | settings/destinataires | cocher agents | ~30 s | ok | Fonctionne (`whatsapp-recipients`) | — |
| Payer / renouveler | 3-4 taps | facturation → Flouci | montant | ~1-2 min | fiabiliser | **Pas de webhook** : si la fenetre se ferme apres paiement, statut bloque `pending`→`expired` malgre encaissement (`PaymentController.php:119-121`) | **MOY** : job de reconciliation + webhook Flouci |
| Convertir le trial en payant | **impossible en self-service** | — | — | — | 1 flux | Aucun endpoint checkout hotel ; l'admin Qayed doit creer une facture a la main | **STR** (voir C) |

### B.3 Admin Qayed (platform_admin)

| Tache | Taps/Etat | Friction | Correctif |
|-------|-----------|----------|-----------|
| Vue client 360 | Multi-endpoints a assembler | Pas d'endpoint unifie ; paiements, timeline d'abonnement, activite par org disperses (`OrganizationAdminController::show` ne joint ni invoices ni `SubscriptionEvent` ni audit) | **MOY** : endpoint `hosts/{id}/overview` agregeant sub + invoices + paiements + events + derniere activite + statut dunning |
| Convertir un trial | Manuel (creer facture) | Pas d'action « convertir / emettre 1re facture » en un clic | **MOY** : bouton « emettre facture de conversion » |
| Suivre le MRR | KPI existant, correct pour self-service | Churn sous-compte : `cancelled_at` jamais ecrit sur annulation admin (`SubscriptionAdminController.php:84-91`) → KPI churn aveugle | **QW** : ecrire `cancelled_at` a l'annulation |
| Superviser WhatsApp | Page dediee + health | OK (pause/resume, logs, resend) | — |

### B.4 Agent d'autorite (authority_user)

| Tache | Taps | Friction | Correctif |
|-------|-----:|----------|-----------|
| Se connecter (2FA obligatoire) | login + code TOTP | 2FA correcte, credential verifie | — |
| Rechercher un voyageur | 2-3 taps | Scoping gouvernorat par **sous-chaine** `ilike %gov%` (`AuthoritySearchController.php:44`) → risque de sur/sous-correspondance | **MOY** : comparaison exacte normalisee |
| Exporter une fiche | 2 taps (PDF/CSV) | Export non journalise comme une transmission (pas de log par export cote portail) | **MOY** : journaliser chaque export au meme titre que `whatsapp_send_log` |
| App « Arabic-first » | — | **Non Arabic-first** : herite de la langue globale (defaut fr) ; aucune force `ar`/RTL cote autorite | **QW** : defaut `ar` + RTL pour le role authority au login |

## C. Points de friction transverses (classes)

### Quick Wins (< 1 jour) — a valider avant implementation
1. **Retirer les emoji** de `ReportNextCheckin.php:92-93` (regle 4). [outbound email]
2. **Gater/retirer les `console.log` de PII** dans `src/lib/cinScanner.ts:388,393,410,452,464` et `mrzScanner.ts:178` (noms, CIN, DOB, MRZ en clair dans la console navigateur, sans garde `import.meta.env.DEV`) — **conformite INPDP**.
3. **Corriger le bug `sex`** : aligner front/back (soit rendre `sex` optionnel back avec inference serveur, soit forcer la saisie front avant submit).
4. **Ecrire `cancelled_at`** a l'annulation d'abonnement (repare le KPI churn).
5. **Deep-link post-login receptionniste** vers le wizard (ou raccourci nav).
6. **Authority en `ar`+RTL par defaut** pour le role.
7. **Supprimer les fichiers junk** trackes (`3`, `React`, `TUN)`, `min`, `SQLSTATE[22001]`), sortir `.phpunit.result.cache` du suivi, supprimer `backend-handoff/` (doublon derive) et `EXPLORATION-CIN.md` du repo front.

### Moyens (< 1 semaine)
8. **Wizard scan-first + chambre par defaut + fusion confirmations** (vise la regle ≤3 taps ; peut etre livre en 2 iterations MOY→STR).
9. **Ne pas blanchir les champs low-confidence** : pre-remplir en gris + focus.
10. **Report property/dates** entre voyageurs d'un meme sejour.
11. **Webhook + reconciliation Flouci** (paiement fantome).
12. **Endpoint admin « client 360 »** unifie + bouton conversion trial.
13. **Journalisation des exports autorite** + scoping gouvernorat exact.
14. **CI backend** (phpunit/pint) : le repo le mieux teste n'a aucun gate.

### Structurels
15. **Cabler le prefill client recurrent** : route hotel `guests/search?cin=` scoping tenant + `X-Property-Id`, brancher `existingClient` (`cin.ts:139-166`). Elimine la double-saisie du client fidele — coeur de la regle 2.
16. **Offline-first terrain** : file de check-ins locale (IndexedDB) + synchro differee + gestion `navigator.onLine` (regle 5). Le scan MRZ etant deja local, seul l'appel Vision de repli exige le reseau.
17. **Machine a etats d'abonnement explicite** (etat `past_due`, transitions gardees) + **self-service checkout trial→payant** (voir AUDIT-04).
18. **Refactor des God-components** : `PropertiesPage.tsx` (1009 l.), `SettingsPage.tsx` (919), `GuestScanPanel.tsx` (694) cote front ; `OrganizationController` (550), `AuthoritySearchController` (519) cote back.

## D. Soustraction (simplicite par le retrait)

- **`backend-handoff/`** dans le repo front : doublon derive, source de confusion → supprimer.
- **`EXPLORATION-CIN.md`** : doc de phase 0 historique → archiver hors racine.
- **Prefill client recurrent** : soit on le cable (recommande), soit on retire le code mort (`lookupExistingClient`) qui ajoute jusqu'a 3 s de latence pour rien quand `QAYED_GUEST_LOOKUP_PATH` pointe vers un 404 (`cin.ts:295-297`).
- **Etape « booking » du wizard** : la fusionner dans le flux scan-first ; pour les fiches pre-creees, le deep-link `?resume={id}` existe deja et saute cette etape (`DashboardPage.tsx:222`) — le generaliser.
