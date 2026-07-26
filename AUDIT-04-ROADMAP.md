# AUDIT 04 — Existant & projection future

> **Skills mobilises : `tech-debt` (inventaire/priorisation) + `testing-strategy` (couverture) + `documentation` (etat doc)** — non installes dans cet environnement ; methodologie appliquee via agents a preuve.

## 1. Backlog unique priorise (impact x effort)

Consolidation des correctifs AUDIT-02 (friction) + AUDIT-03 (marketing) + dette. Score = Impact (1-5) / Effort (1-5). Trie par ROI decroissant.

| # | Action | Type | Impact | Effort | ROI | Repo |
|---|--------|------|:------:|:------:|:---:|------|
| 1 | Gater/retirer les `console.log` de PII (CIN/MRZ) en prod | Conformite INPDP | 5 | 1 | ⭐⭐⭐⭐⭐ | front |
| 2 | Retirer emoji email `ReportNextCheckin` | Conformite regle 4 | 3 | 1 | ⭐⭐⭐⭐⭐ | back |
| 3 | Corriger bug `sex` (422 mute sur scan propre) | Friction quotidienne | 4 | 1 | ⭐⭐⭐⭐⭐ | front/back |
| 4 | Ecrire `cancelled_at` a l'annulation (repare churn KPI) | Data/metrique | 3 | 1 | ⭐⭐⭐⭐⭐ | back |
| 5 | Nettoyer junk + `.phpunit.result.cache` + `backend-handoff/` + README | Dette/hygiene | 3 | 1 | ⭐⭐⭐⭐⭐ | 2 repos |
| 6 | CI backend (phpunit + pint) | Qualite/regression | 5 | 2 | ⭐⭐⭐⭐ | back |
| 7 | Parrainage 1-tap (coupons + wa.me) | Croissance | 5 | 2 | ⭐⭐⭐⭐ | 2 repos |
| 8 | Deep-link post-login → wizard + authority `ar`/RTL defaut | Friction | 3 | 1 | ⭐⭐⭐⭐ | front |
| 9 | Rapport mensuel conformite PDF | Retention | 4 | 2 | ⭐⭐⭐⭐ | back |
| 10 | Wizard scan-first + chambre par defaut (regle ≤3 taps) | Friction coeur | 5 | 3 | ⭐⭐⭐⭐ | front |
| 11 | Cabler prefill client recurrent (route lookup CIN scoping tenant) | Friction/regle 2 | 4 | 3 | ⭐⭐⭐ | 2 repos |
| 12 | Webhook + reconciliation Flouci (paiement fantome) | Revenu/fiabilite | 4 | 3 | ⭐⭐⭐ | back |
| 13 | Self-service checkout trial→payant + etat `past_due` | Monetisation | 5 | 4 | ⭐⭐⭐ | 2 repos |
| 14 | Endpoint admin « client 360 » unifie | Ops | 3 | 2 | ⭐⭐⭐ | back |
| 15 | Tests CIN scan + 2FA + Flouci (les 3 trous critiques) | Qualite | 5 | 4 | ⭐⭐⭐ | 2 repos |
| 16 | Ne pas blanchir champs low-confidence (pre-remplir gris) | Friction | 3 | 2 | ⭐⭐⭐ | front |
| 17 | Stabilisation WhatsApp : adapter transport + durcissement | Fiabilite | 4 | 4 | ⭐⭐ | back |
| 18 | Offline-first terrain (file IndexedDB + synchro) | Regle 5 | 4 | 5 | ⭐⭐ | front |
| 19 | Refactor God-components/controllers | Dette | 2 | 4 | ⭐ | 2 repos |

### Focus 1.a — Stabilisation WhatsApp (chantier #17)
Etat : relais `whatsapp-web.js` (Puppeteer) explicitement **provisoire** (« a retirer apres homologation MI »). Robuste sur les pannes transitoires (backoff exponentiel 1/5/15/60/240 min, watchdogs QR/liveness/timeout, auto-restart conteneur, alertes heartbeat) mais **fragile sur les changements structurels Meta** :
- **Pin de version WA Web expire le 2026-09-10** (`index.js:128-132`) → panne datee silencieuse. **Action immediate** : surveiller + planifier le remplacement avant cette date.
- Patch monkey-patch `node_modules` (perdu sans `patch-package`).
- **Doublons possibles** : `enqueueForCheckIn` sans garde d'idempotence ; le timeout d'envoi n'annule pas la promesse sous-jacente → message potentiellement livre deux fois.

Recommandation : **introduire une interface `WhatsappTransport`** (`send(recipient, caption, mediaUrl): SendResult`, `sessionStatus()`) avec impl `WwebjsTransport` (actuelle) et `CloudApiTransport`. L'outbox (claim FIFO `SKIP LOCKED`, backoff, journal, dedup) reste intacte — le couplage `whatsapp-web.js` est deja confine a `whatsapp-service/index.js`. **Evaluer l'API WhatsApp Business Cloud (officielle)** : effort code faible-modere (REST + webhooks, supprimerait Node+Puppeteer+patch), MAIS contrainte produit : les messages business-initiated exigent des **templates pre-approuves** et le free-form n'est permis que dans la fenetre 24 h user-initiated. Le modele « push d'une fiche a chaque check-in » devrait passer chaque fiche en template approuve. Decision a prendre avant fin aout 2026 (avant l'expiration du pin). Ajouter la dedup sur `message_id` et une garde d'idempotence sur `enqueueForCheckIn`.

### Focus 1.b — Fiabilisation du scan CIN (chantiers #3, #15, #16)
- Bug `sex` (422 mute) : Quick Win.
- **Zero test** sur ~1000 lignes de scan (`cinScanner.ts`, `mrzScanner.ts`, `api/scan/*`) — code le plus complexe et le plus critique du produit. Prioriser des tests d'extraction sur fixtures (MRZ + CIN, cas low-confidence).
- Ne pas blanchir les champs douteux (re-saisie forcee) → pre-remplir en gris editable.

### Focus 1.c — Panel admin self-service (chantiers #4, #12, #13, #14)
- Dunning : deja complet et idempotent (relances J+3/7/14, suspension J+21) — **point fort a preserver**.
- Manques : pas de webhook Flouci (paiement fantome), pas d'etat `past_due` (21 j d'acces total impaye), pas de conversion self-service, pas de vue client 360 unifiee, churn KPI aveugle (`cancelled_at`).

## 2. Dette technique & tests — synthese (skills `tech-debt`, `testing-strategy`)

- **Tests** : back 24 fichiers (bon : tenant isolation, authority scoping, dunning, relais WhatsApp) ; **mais CI backend absente** (le repo le mieux teste n'a aucun gate). Front : 2 fichiers seulement, UI/scanners non testes. Trous critiques : **CIN scan, 2FA, Flouci** (aucun test). Le front a une CI (lint+typecheck+test) mais le filet est mince.
- **Documentation** : README front = template Vite non modifie ; **backend sans README** ; pas de `.env.example` front (et `.env.production` commit une URL backend reelle) ; connaissance dispersee dans `EXPLORATION-CIN.md` et `backend-handoff/`. Seul bon doc : `.env.example` backend. → Ecrire un README par repo + `.env.example` front (chantier hygiene).
- **Dette code** : peu de TODO/FIXME (dette non documentee plutot qu'absente) ; God-components/controllers ; dead code `// ProcessPassportScan::dispatch($scan)` (`CheckInService.php:307`) ; junk files trackes.
- **Securite** : token en `localStorage` (blast-radius XSS, surtout autorite) → envisager cookie httpOnly ou tokens autorite courts ; scoping gouvernorat par sous-chaine ; fallback tenant elargissant le scope ; mot de passe admin par defaut dans un seeder (`PlatformAdminSeeder`).

## 3. Projection strategique — roadmap 6/12/24 mois

Thèse : **Qayed = infrastructure souveraine d'identite voyageur**, architecture biface (operateur + portail autorite) comme differenciateur defensif, expansion MENA (obligation legale identique au Maroc, en Algerie, en Egypte, en Jordanie).

### Horizon 6 mois — « Solidifier le produit tunisien »
- Backlog #1-#12 (conformite, friction coeur ≤3 taps, croissance parrainage + rapport mensuel, fiabilite paiement, CI backend).
- **Decision WhatsApp** avant l'expiration du pin (2026-09-10) : Cloud API vs durcissement.
- Objectif business : entonnoir signup→conversion self-service etanche (#13), premiers effets du parrainage.

### Horizon 12 mois — « Poser les fondations d'internationalisation sans surcharger le produit TN »
Blocages actuels a l'i18n (identifies en code) et fondations a poser **maintenant, en refactor invisible pour l'operateur TN** :
1. **Abstraction identite** : le regex CIN 8 chiffres (`cinScanner.ts:258`, `otaDetect.ts:22`) est cable en dur. Introduire un **registre de formats de piece par pays** (`IdentityDocumentSpec`: pattern, champs, sens de lecture) ; la CIN TN devient une entree, pas une hypothese. Le MRZ passeport etant deja standard ICAO, l'etranger est deja gere.
2. **Abstraction monetaire** : `formatTND`/`money.ts` code TND partout. Introduire une couche `Money(amount, currency)` + formatage par locale. Prerequis multi-devise (MAD, DZD, EGP, JOD).
3. **Abstraction locale/date** : ~20 fichiers avec ternaires `ar-TN/en-GB/fr-FR`. Centraliser dans un `formatDate(value, locale)` unique (une seule source a etendre).
4. **Multi-juridiction** : `AuthorityDashboardPage.tsx:198` suppose UNE autorite nationale. Modeliser `Jurisdiction` (pays → autorites → gouvernorats/regions) pour rendre le portail autorite reutilisable ailleurs.
5. **Fuseau** : sortir l'hypothese `UTC+1 Tunisie` (`CheckInWizardPage.tsx:27`) vers la config etablissement.
6. **Translitteration** : le dictionnaire tunisien (`arabicTransliteration.ts`) doit devenir pluggable par convention regionale (maghrebine/levantine/egyptienne).
7. **i18n** : deja pret (fr/en/ar quasi-parite, RTL app-wide) — combler les 22 `_one` arabes, retirer les copies landing hors-t().

Principe : **ces fondations sont des refactorings internes** (registres, abstractions) qui ne changent rien au parcours de la receptionniste tunisienne. On ne livre PAS de features multi-pays a 12 mois — on rend le code capable de les accueillir.

### Horizon 24 mois — « Expansion MENA + defensibilite »
- **Deuxieme marche pilote** (Maroc ou Jordanie) sur les fondations posees : nouveau `IdentityDocumentSpec`, devise, juridiction, translitteration.
- **Portail autorite comme produit souverain** : chaque Etat obtient son instance/juridiction, meme socle biface — c'est le fosse defensif (un concurrent doit reconstruire les DEUX faces + la convention ministerielle).
- **Interoperabilite** : preparer une transmission machine-to-authority officielle (API gouvernementale) la ou elle existe, remplacant definitivement le relais WhatsApp provisoire.
- **Contrainte non negociable maintenue** : la watchlist autorite reste **strictement sous convention ministerielle** ; aucune extension autonome. Le sync OpenSanctions (sanctions publiques) reste distinct de toute watchlist ministerielle.

## 4. Ce qui, dans le code actuel, bloque l'internationalisation (recapitulatif)

| Blocage | Fichier(s) | Fondation a poser |
|---------|-----------|-------------------|
| CIN 8 chiffres en dur | `cinScanner.ts:258`, `otaDetect.ts:22` | Registre `IdentityDocumentSpec` par pays |
| Devise TND en dur | `money.ts:8-14`, `PricingSection.tsx` | Couche `Money` + format par locale |
| Locales date `*-TN` | ~20 fichiers (`dateLocaleFor`) | `formatDate(value, locale)` centralise |
| Telephone `+216` | `AdminAuthorityPage`, `ProfilePage`, `SettingsPage` | Format telephone par pays |
| Fuseau UTC+1 | `CheckInWizardPage.tsx:27`, `EditCheckInModal.tsx:14` | Fuseau au niveau etablissement |
| Autorite nationale unique | `AuthorityDashboardPage.tsx:198` | Modele `Jurisdiction` multi-pays |
| Translitteration tunisienne | `arabicTransliteration.ts` | Convention regionale pluggable |
| Non Arabic-first autorite | `i18n/index.ts` (defaut fr) | Langue par role/juridiction |
