# AUDIT 01 — Cartographie complete du projet Qayed

> Audit 360, juillet 2026. Perimetre : monorepo Qayed (qayed.tn) — digitalisation de la fiche de police obligatoire pour l'hebergement touristique en Tunisie.
> Ce document est une cartographie factuelle. Les jugements (friction, dette, roadmap) sont dans AUDIT-02/03/04.
> **Skill mobilise pour cette section : `architecture` / `system-design`** (indisponible en tant que skill installe dans cet environnement — voir ETAPE 0 de la synthese ; methodologie appliquee manuellement + agents d'exploration paralleles).

## 0. Composition du monorepo

| Repo | Role | Stack | Volumetrie |
|------|------|-------|-----------|
| `checktunisia` | Frontend operateur + autorite + admin + fonctions scan | React 18 / Vite 5 / TS 5.5, TailwindCSS 3, react-router 6, react-query 5, zustand, i18next. Fonctions serverless Vercel (`api/`) | 42 pages, 29 composants, ~30 fichiers `src/api`, 3 locales |
| `checktunisia-backend` | API metier + portail autorite + admin + relais WhatsApp | Laravel 12 / PHP 8.3, Sanctum, spatie/permission, google2fa, dompdf, resend, flysystem-s3. Service Node `whatsapp-service` (whatsapp-web.js) | 49 controleurs, 39 modeles, 55 migrations, 11 commandes planifiees |

Architecture **biface** confirmee : une meme base de donnees sert deux populations opposees (operateurs d'hebergement vs agents d'autorite), separees par role, middleware et sous-domaine applicatif. C'est le differenciateur strategique — et le point de vigilance securite n°1.

## 1. Inventaire des surfaces

### 1.1 Web app operateur (React, `src/pages/hotel`)
Surface quotidienne. Routes (cf. `src/App.tsx:135-150`) :
- `/hotel/onboarding` — configuration initiale (proprietes, chambres)
- `/hotel/dashboard` — tableau de bord
- `/hotel/check-ins/new` — **CheckInWizardPage** : le flux critique (scan CIN + fiche)
- `/hotel/history` + `/hotel/history/:id` — historique des fiches
- `/hotel/properties` — bascule multi-proprietes
- `/hotel/settings` — parametres, destinataires WhatsApp, utilisateurs
- `/hotel/security` — 2FA, mot de passe
- `/hotel/payment/success|failed` — retour Flouci

### 1.2 Mobile app (v2)
Pas d'app native distincte dans le repo : la strategie mobile est **PWA**. `public/manifest.webmanifest` (display `standalone`, orientation portrait) + `public/sw.js`. Le service worker est **volontairement sans cache** (`sw.js` : passthrough, `skipWaiting`+`clients.claim`) : il existe uniquement pour rendre l'app installable et obtenir la permission camera permanente. **Consequence majeure : aucune capacite offline** (voir AUDIT-02, regle d'or n°5 non respectee).

### 1.3 Admin panel (React, `src/pages/admin` — 16 pages)
Dashboard, hosts, hotels, users, authority, subscriptions, facturation, coupons, payments, emails, activity, whatsapp, pages (CMS Puck), menus, ai-costs. Gate `role:platform_admin` (`routes/api.php:328-329`).

### 1.4 App autorite (React, `src/pages/authority` — 9 pages, Arabic-first RTL)
Dashboard, search, guests/:id, hotels, hotels/:id, alerts, watchlist, activity, 2fa/setup. Backend sous `role:authority_user` + `require.2fa` + `authority.credential` + `throttle:60,1` (`routes/api.php:301-302`). Population ministere/police.

### 1.5 Automatisation WhatsApp
Trois etages : (a) API Laravel `internal/whatsapp/*` protegee par middleware `whatsapp.worker` (`routes/api.php:92-99`) exposant une file (`next`), un canal de controle et l'ingestion de resultats/session ; (b) modeles `WhatsappSendLog` + `WhatsappSessionState` (outbox + etat de session) ; (c) worker Node `whatsapp-service/index.js` (whatsapp-web.js + Puppeteer, patch `whatsapp-web.js+1.34.7.patch`). Supervision : `health/whatsapp`, commande `whatsapp:check-health` (heartbeat > 10 min → alerte admin), purge horaire `whatsapp:purge-images` (retention 24 h). **Detail et fragilite : AUDIT-02 / AUDIT-04.**

### 1.6 Scan CIN / passeport OCR (contrainte INPDP : zero persistance image)
Pipeline hybride : MRZ locale d'abord (`api/_lib/mrzExtraction.ts`, lib `mrz` + chiffres de controle), Claude Vision en repli (`api/scan/cin.ts`, `api/_lib/cinExtraction.ts`, `@anthropic-ai/sdk`) uniquement en cas de faute. Endpoint local `scan-events/mrz-local` (`routes/api.php:191`). Cout AI trace (`api/_lib/aiUsageTracking.ts` → `internal/ai-usage`). **Conformite zero-persistance : verifiee en AUDIT-02.**

## 2. Inventaire des roles et parcours reels

Roles definis dans `database/seeders/RolesAndPermissionsSeeder.php` et exposes dans `app/Models/User.php:90-110` :

| Role | Surface | Parcours reel (resume) |
|------|---------|------------------------|
| `receptionist` | Web/PWA operateur | Login (+2FA si active) → Dashboard → `/hotel/check-ins/new` → scan CIN → validation fiche → transmission auto. Ne gere ni users, ni chambres, ni facturation. Peut basculer entre proprietes (`my-properties`). |
| `hotel_admin` (manager multi-proprietes) | Web operateur | Tout le parcours receptionniste + onboarding, gestion proprietes/chambres (`organization/properties/*`), utilisateurs, destinataires WhatsApp, facturation/paiements Flouci, invoices PDF. |
| `platform_admin` (admin Qayed) | Admin panel | Vue plateforme : hotels, hosts, abonnements, facturation, paiements, coupons, emails, CMS, WhatsApp, couts AI, KPI. |
| `authority_user` (agent d'autorite) | App autorite RTL | Login + 2FA obligatoire + credential autorite → dashboard, recherche voyageurs, fiches hotel, alertes, watchlist (sous convention). Lecture + journalisation stricte. |

Regle produit critique : **l'acces multi-proprietes n'est jamais conditionne par le role**. Indice positif dans le routing : `hotel/my-properties` est ouvert a `role:hotel_admin|receptionist` (`routes/api.php:166`), la gestion des proprietes reste `hotel_admin`. Verification exhaustive (deep-links inclus) en AUDIT-02.

## 3. Inventaire technique

### 3.1 Stack et dependances cles
- **Frontend** : React 18.3, Vite 5.4, react-router 6.26, @tanstack/react-query 5, zustand 5, i18next 26 (+ language detector), zod 3, tailwind 3.4. OCR : `mrz` 3.3, `tesseract.js` 5.1 (+ `public/worker.min.js`, `public/tessdata`), `@techstark/opencv-js`, `heic2any`. CMS : `@measured/puck` 0.20. `@anthropic-ai/sdk` 0.68 (Vision, cote serverless).
- **Fonctions serverless** (`api/`, `@vercel/node`) : `scan/cin`, `scan/mrz`, + `_lib` (extraction, tracking cout AI, mois tunisiens). C'est ici que vit l'appel Claude Vision, hors du navigateur (cle API protegee).
- **Backend** : Laravel 12, PHP 8.3, Sanctum (tokens), spatie/laravel-permission (RBAC), pragmarx/google2fa (2FA), barryvdh/laravel-dompdf (factures/PDF), resend/resend-php (email), league/flysystem-aws-s3 (stockage), intervention/image.
- **Worker WhatsApp** : Node + whatsapp-web.js 1.34.7 (patche) + Puppeteer.

### 3.2 Charte graphique (conformite)
`tailwind.config.js` porte la palette exacte de la charte : encre `#10222E`, papier `#F6F5F1`, cachet `#5346A8`, conforme `#1F9D6B`, vigilance `#E3A008` (+ variantes). Polices : `src/index.css:1` importe Archivo (axe variable jusqu'a 900) + IBM Plex Sans + IBM Plex Sans Arabic + IBM Plex Mono. Le RTL arabe bascule proprement sur IBM Plex Sans Arabic (max 700) — documente dans BRAND.md. Anciennes classes `navy-*`/`gold-*`/`warm-*` remappees sur la nouvelle palette (alias). Verification off-charte composant par composant : AUDIT-02.

### 3.3 Points de fragilite connus
- **Puppeteer / whatsapp-web.js** : session QR volatile, timeouts, reconnexion — patch maison + heartbeat + purge images 24 h (module explicitement etiquete PROVISOIRE dans `routes/console.php`).
- **Persistance image WhatsApp** : le relais conserve des images de documents jusqu'a 24 h avant purge (`whatsapp:purge-images`) — a mettre en regard de la contrainte zero-persistance (voir AUDIT-02 / AUDIT-04).
- **Offline** : inexistant (cf. 1.2).
- **Fonts** : import Google Fonts render-blocking dans `index.css` (perf).

### 3.4 Machine a etats d'abonnement, Flouci, MRR
Services `app/Services/Subscription`, `Billing`, `Payment` ; modeles `Subscription`, `SubscriptionEvent`, `SubscriptionPlan`, `Invoice`, `Payment`, `Coupon`. Ordonnanceur (`routes/console.php`) : `subscriptions:notify-expiring` (J-, 08:00), `subscriptions:expire-overdue` (03:00), `invoices:generate-due` (J-7, 07:00), `invoices:dunning` (relances J+3/7/14, suspension J+21, 07:30). MRR/KPI : `Admin/KpiController`. Etat reel (explicite vs disperse) et completude Flouci : AUDIT-02 / AUDIT-04.

### 3.5 Taches planifiees (source unique `routes/console.php`)
Abonnements (3), facturation/dunning (2), check-ins (notify-pending 10 min, departures-due 14:00 Tunis, report-next 1 min arme a la demande), watchlist (`watchlist:sync-opensanctions` 02:00 — Interpol Red Notices + UN Sanctions via OpenSanctions), WhatsApp (purge-images horaire, check-health 10 min). Push : `SendExpoPushJob`.

## 4. Carte des flux critiques

1. **Creation de fiche / check-in avec scan CIN** (receptionniste) : ouverture app → `/hotel/check-ins/new` (CheckInWizardPage) → capture document → MRZ locale (ou Claude Vision en repli) → prefill → complement manuel → `POST check-ins` puis `guests`, `scans`, `complete` (`routes/api.php:195-203`, gate `subscription.active`). Detail tap/ecran : AUDIT-02.
2. **Envoi a l'autorite** : la fiche finalisee alimente le portail autorite (meme base) + relais WhatsApp (outbox `WhatsappSendLog` → worker Node). Tracabilite via `audit` middleware + `AuditLog`. Chemin exact : AUDIT-02.
3. **Provisioning trial self-service** : `public/register` (front) → `PublicRegistrationController::register` (`routes/api.php:67`, throttle 5/10) → onboarding (`OnboardingController`) → abonnement trial. Objectif < 5 min : evalue en AUDIT-02.
4. **Facturation / dunning** : `payments/initiate` + `verify` + `declare-virement` (Flouci + virement) → invoices PDF → relances automatiques J+3/7/14 → suspension J+21. Completude : AUDIT-02.
5. **Check-out + watchlist** : `check-ins/{id}/checkout`, hits watchlist (`watchlist-hits`), sync OpenSanctions. La watchlist autorite opere sous convention ministerielle — perimetre strictement encadre (voir contraintes transversales).

## 5. Schema d'ensemble

```
                     +---------------------------+
   Receptionniste -->|  PWA / Web operateur      |--\
   Manager       -->|  (React, scan CIN local)  |   \
                     +---------------------------+    \  Sanctum
                                                       \  (auth:sanctum + role + tenant)
   Vercel serverless (api/scan) --Claude Vision-->      v
   MRZ local (mrz/tesseract)                        +--------------------+
                                                    |  Laravel 12 API    |
   Admin Qayed ---> Admin panel (React) ----------->|  RBAC spatie       |
                                                    |  Billing/Sub/Pay   |
   Agent autorite -> App autorite RTL ------------->|  Watchlist/Audit   |
                                                    +---------+----------+
                                                              | outbox (WhatsappSendLog)
                                                              v
                                              Worker Node whatsapp-service
                                              (whatsapp-web.js + Puppeteer)
```
