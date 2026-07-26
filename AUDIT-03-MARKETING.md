# AUDIT 03 — Couche marketing sans complexite (version enrichie)

> **Skill mobilise : `architecture` / `system-design`** (non installe ; methodologie manuelle, constats cites en `fichier:ligne`).
> **Contrainte absolue : rien ne s'ajoute au parcours quotidien de l'operateur.** Le marketing vit en peripherie — cron planifie, page publique, ecran admin/settings — **jamais** dans le wizard de check-in.
> **3 garde-fous transverses non negociables** appliques a chaque piste :
> - **Zero emoji** dans toute communication sortante (regle produit ; deja violee aujourd'hui dans `ReportNextCheckin.php:92-93` — a ne pas reproduire).
> - **INPDP** : aucune PII voyageur dans un artefact marketing ; agregats non reversibles uniquement ; aucune donnee derivee de la watchlist/autorite.
> - **Charte** : sceau قيد a −6° (`QayedStamp.tsx`), palette encre/papier/cachet, polices IBM Plex — tout PDF/page/badge doit etre a la charte.

---

## 0. Cadre d'evaluation

Chaque piste est notee sur : **Effort** dev, **Impact** (acquisition/retention), **Risque de complexification du parcours operateur** (doit etre nul/quasi-nul), **Verdict** (accepte / rejete / ameliore), puis specifiee : **reutilisation existante (file:line)**, **specification technique**, **modele de donnees**, **metriques de succes**, **garde-fous**.

**Brique reutilisable transverse — le pattern « cron idempotent »** deja eprouve dans le dunning : une commande planifiee dans `routes/console.php`, un flag d'idempotence en `metadata` (ex. `metadata.dunning_sent` dans `BillingService::runDunning`), un envoi via `SystemMailer`/Resend, un rendu PDF via `barryvdh/laravel-dompdf` (deja utilise pour les factures). **Toutes** les pistes « push » (rapport mensuel, invitation avis) copient ce pattern — cout marginal quasi nul, zero risque parcours.

---

## 1. Rapport mensuel automatique de conformite (PDF brande قيد)

**Verdict : ACCEPTE — priorite 2.** La piste la plus « zero-effort operateur » : le produit demontre sa valeur tout seul, chaque mois, sans que l'operateur touche a rien.

### Reutilisation existante
- `barryvdh/laravel-dompdf` (deja en place pour `invoices/{id}/pdf`).
- Scheduler `routes/console.php` + pattern `ReportNextCheckin` / dunning (cron + idempotence + `SystemMailer`).
- `Admin/KpiController` : fiches transmises, activation, deja calcules — les memes agregations, scopees par organisation.
- Sceau `QayedStamp` (−6°) + palette charte pour l'en-tete PDF.

### Specification technique
- **Commande** `reports:monthly-compliance` planifiee `->monthlyOn(1, '08:00')->timezone('Africa/Tunis')` dans `routes/console.php`.
- Pour chaque organisation `active` : agreger le mois ecoule (nb fiches transmises, taux de conformite = fiches transmises / check-ins finalises, temps estime economise).
- Rendu PDF `resources/views/pdf/monthly-compliance.blade.php` (gabarit charte, sceau, en-tete etablissement) → email `SystemMail` au(x) `hotel_admin` (destinataires = gerants, pas receptionnistes).
- Idempotence : flag `metadata.compliance_report_sent = 'YYYY-MM'` sur l'organisation (jamais deux envois pour le meme mois).
- **Chiffre « minutes economisees » honnete** : `nb_fiches × delta_temps_documente` ou `delta_temps_documente` est une constante **sourcee** (mesure papier vs Qayed, documentee dans le code), pas un nombre invente. Si non mesure, afficher « fiches transmises » et « taux de conformite » seulement.

### Metriques de succes
- Taux d'ouverture email rapport ; correlation rapport recu ↔ retention M+1 ; NPS gerant.

### Garde-fous
- **Sans emoji** (contrairement a `ReportNextCheckin.php:92-93`). INPDP : agregats seulement, jamais un nom/CIN voyageur. Ne pas envoyer si l'abonnement est suspendu (incoherent).

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Faible (1 cmd + 1 template PDF + 1 email) | Retention ++ | Nul |

---

## 2. Badge « Etablissement conforme Qayed » (widget web + certificat imprimable)

**Verdict : ACCEPTE et AMELIORE — priorite 4, en 2 temps.** Amelioration cle : rendre le badge **verifiable et revocable** pour eviter les faux badges et proteger la marque.

### 2.a Certificat imprimable (temps 1 — trivial)
- Reutilise dompdf + `QayedStamp`. Bouton « Telecharger mon certificat » dans `/hotel/settings` (hors flux quotidien).
- Affichette A5/A4 a poser a la reception : « Etablissement conforme — fiche de police digitale », sceau قيد, date, nom etablissement.

### 2.b Widget web embarquable (temps 2 — moyen)
- **Endpoint public signe** : `GET /public/badge/{orgToken}.svg` → SVG a la charte, `Cache-Control` court + CDN.
- **Page de verification** : `GET /verify/{orgToken}` (CMS public) → « Etablissement X, conforme au JJ/MM/AAAA » ou « badge revoque ».
- Snippet a coller sur le site de la guesthouse (backlink SEO vers qayed.tn).

### Modele de donnees
- Ajouter `organizations.badge_token` (UUID, nullable) + `badge_enabled` (bool).
- **Revocation automatique** : si l'abonnement passe `suspended`/`expired`, le badge affiche « non actif » (coherent avec la machine a etats — voir AUDIT-04). Aucune nouvelle table.

### Metriques de succes
- Nb badges actifs ; trafic entrant depuis backlinks badge ; taux de conversion visiteurs `/verify` → signup.

### Garde-fous
- Token **revocable** et **non enumerable** (UUID, pas d'ID sequentiel). Le badge ne revele **aucune** metrique sensible (juste « conforme au JJ/MM »). Charte stricte sur le SVG.

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Faible (certificat) → Moyen (widget) | Acquisition + (organique/SEO) | Nul |

---

## 3. Parrainage a un tap — **MEILLEUR ROI acquisition, priorite 1**

**Verdict : ACCEPTE et AMELIORE.** Le gerant partage un lien WhatsApp pre-redige ; mois offert des deux cotes. Amelioration cle : **credit a l'activation reelle (1er check-in du filleul), pas au signup** — anti-abus.

### Reutilisation existante (point technique important)
- **Le lien pre-redige est un `wa.me/?text=...` cote client** (front) → **n'utilise PAS le relais Puppeteer fragile** (dont le pin de version WA Web expire le 2026-09-10, cf. AUDIT-04). Zero charge sur l'infra WhatsApp instable. C'est ce qui rend cette piste sure.
- Infra coupons **deja en place** : `Coupon` (percent/fixed, `max_uses`, `used_count`, `expires_at`, `active`), `CouponRedemption` (unique par facture, `organization_id`, `amount_discounted`), `CouponController` (`app/Http/Controllers/Admin/CouponController.php`).
- `KpiController` : l'activation (1er check-in) est deja un evenement suivi.

### Design honnete du « mois offert » (contrainte reelle)
Les coupons s'appliquent **au niveau facture, avant taxe** (cf. `Coupon.php` : « portant sur le montant HT »). Il n'existe pas aujourd'hui de notion de « credit mois » independante d'une facture, et **la conversion trial→payant self-service n'existe pas** (cf. AUDIT-04). Deux implementations possibles :
- **Option A (recommandee) — extension de periode d'abonnement** : `subscriptions.expires_at += 1 mois` pour parrain ET filleul a l'activation. Fonctionne meme sans facture, aligne avec l'absence de checkout self-service, simple a raisonner. Un `SubscriptionEvent` de type `referral_credit` trace l'operation.
- **Option B — coupon 100% auto-genere** sur la prochaine facture de chacun. Reutilise `CouponRedemption` mais depend d'une facture a venir (fragile pour un trial qui n'a pas encore converti).
→ **Recommander A**, garder B comme fallback si la facturation devient le pivot.

### Specification technique
- **Modele** : `organizations.referral_code` (slug court unique, ex. `QAYED-AB12CD`) genere a la creation.
- **Front** : ecran « Parrainer » dans `/hotel/settings` (hors wizard) : bouton unique → `window.open('https://wa.me/?text=' + encodeURIComponent(texte))`. Texte pre-redige **sans emoji**, avec le lien `qayed.tn/r/{referral_code}`.
- **Landing** : `GET /r/{code}` → page signup pre-remplie (attribution du parrainage stockee).
- **Attribution** : `organizations.referred_by` (FK vers l'org parrain), posee au signup.
- **Declenchement du credit** : sur le **1er check-in finalise** du filleul (evenement d'activation existant), une commande/observer applique l'Option A aux deux orgs, **une seule fois** (flag `metadata.referral_credited`).

### Metriques de succes (viral loop)
- **K-factor** = (invitations envoyees / gerant actif) × (taux de conversion invitation → activation). Objectif > 0.3 pour un effet composant.
- Nb parrainages envoyes, filleuls actives, CAC economise vs canaux payants.

### Garde-fous anti-abus
- Credit **a l'activation** (1er check-in reel), jamais au signup → bloque les faux comptes.
- 1 credit par nouvel etablissement **distinct** (verif sur SIRET/identifiant etablissement + email, pas juste email jetable).
- Plafond de credits cumulables par org (`max` mensuel) pour eviter le farming.
- Texte pre-redige **sans emoji**.

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Faible-Moyen | Acquisition ++ (viral) + retention parrain | Nul |

---

## 4. Page publique de statistiques anonymisees (preuve sociale / Startup Act)

**Verdict : ACCEPTE avec RESERVES FORTES — priorite 5.** Utile pour la credibilite (autorites, Startup Act, presse) mais c'est la piste la plus sensible en securite/INPDP.

### Reutilisation existante
- CMS public (Puck) pour la page + `GET /public/stats` agregeant des KPI **anonymises et non reversibles**.

### Specification technique
- Endpoint `GET /public/stats` → cache **long** (CDN, ex. 6-24 h) : `{ total_fiches_traitees, temps_moyen_traitement, nb_etablissements (arrondi) }`.
- Rendu dans une page CMS `/stats` a la charte.

### Reserves (bloquantes tant que non traitees)
- **Agregation non reversible uniquement** : jamais de granularite par etablissement, ville ou gouvernorat qui reidentifierait un petit hotel. **Seuil minimal d'agregation** (ne rien publier sous N etablissements).
- **Pas d'oracle temps-reel** : le cache long evite que la page devienne un signal sur l'activite frontaliere/touristique en direct (risque geopolitique/securite du produit biface).
- **Zero lien avec la watchlist/autorite** : la page ne derive d'aucune donnee du portail autorite.

### Metriques de succes
- Citations presse/dossier Startup Act ; trafic page ; conversion `/stats` → signup.

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Faible | Credibilite + | Nul (parcours) — mais risque **donnees** a maitriser |

---

## 5. Onboarding self-service < 5 min comme argument marketing n°1

**Verdict : ACCEPTE — priorite 3, MAIS conditionne a un prerequis produit dur.** C'est deja presque vrai ; en faire l'argument phare (« operationnel avant la fin de votre cafe »).

### Etat reel (mesure)
- Provisioning trial deja self-service ~2-3 min, **transaction unique** (`PublicRegistrationController::register`), sans verif email bloquante (`email_verified_at => now()`), login immediat. Wizard signup = 4 etapes (`RegisterPage.tsx`).

### Prerequis CRITIQUE (sinon contre-productif)
**La conversion trial→payant n'existe pas en self-service** : `generateDueRenewalInvoices` ne cible que `status='active' AND auto_renew=true` ; un trial (`status='trial', auto_renew=false`) ne recoit jamais de facture ; `PaymentController::initiate` exige une facture existante. **⇒ Un admin Qayed doit creer la facture a la main.** Amplifier l'acquisition avec un onboarding rapide sans reparer ce trou, c'est **alimenter un entonnoir qui fuit a la monetisation** (backlog #13, AUDIT-04).

### Specification (leviers)
- **Prerequis** : endpoint self-service `POST /hotel/subscription/checkout` (choix plan/cycle → facture generee → `payments/initiate` Flouci) + etat `past_due`.
- **Frictions signup a lever** : politique mot de passe min-12 en un seul champ (indicateur de force en direct), creation chambres deportee post-login (deja le cas) mais mise en avant de l'import en masse (`bulk` existe).
- **Marketing** : chrono visible sur la landing, GIF du signup, garantie « pret en 5 min ».

### Metriques de succes
- Taux d'abandon signup ; time-to-first-check-in ; **taux de conversion trial→payant** (le vrai KPI, aujourd'hui bride par le manque de self-service).

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Faible (marketing) + Structurel (prerequis conversion) | Acquisition + | Nul (ameliore l'existant) |

---

## 6. Temoignage integre — 1 invitation Google discrete apres 30 j sans incident

**Verdict : ACCEPTE — priorite 6, tres faible effort.** Une seule invitation, jamais de relance.

### Reutilisation existante
- Activation (1er check-in) + « 30 j sans incident » derivables des KPI/audit ; 1 email `SystemMailer` ; pattern idempotent du dunning.

### Specification technique
- **Commande** `reviews:invite-once` (quotidienne) : cible les orgs activees il y a ≥ 30 j, `metadata.review_invited` non pose, **et sans incident ouvert** (pas d'echec paiement, pas de sub suspendue, pas de fiche en erreur).
- Envoie 1 email `SystemMail` avec le lien d'avis Google ; pose `metadata.review_invited = date`. **Aucune relance** (idempotence stricte, comme `dunning_sent`).

### Metriques de succes
- Nb avis Google generes ; note moyenne ; taux de reponse a l'invitation unique.

### Garde-fous
- **Sans emoji.** Opt-out honore. **Ne jamais** declencher pendant un incident (protege la note).

| Effort | Impact | Risque parcours |
|:---:|:---:|:---:|
| Tres faible | Acquisition ~ (SEO/reputation) | Nul |

---

## 7. Sequencement recommande

Sequence par ROI **et** dependances (pas par numero de piste) :

1. **Sprint 1 (croissance immediate, faible risque)** : Piste 3 (parrainage `wa.me` + coupons/extension) + Piste 1 (rapport mensuel PDF). Toutes deux sur briques existantes, zero infra fragile.
2. **Sprint 2 (etancher l'entonnoir)** : prerequis de la Piste 5 = **checkout self-service trial→payant** (backlog #13) — debloque la valeur marketing de l'onboarding rapide.
3. **Sprint 3 (organique lent)** : Piste 2 (certificat puis badge verifiable) + Piste 6 (invitation avis).
4. **Sprint 4 (credibilite, apres revue securite)** : Piste 4 (stats publiques) une fois les reserves d'agregation/cache validees.

## 8. Recapitulatif priorise

| Piste | Effort | Impact | Risque parcours | Priorite | Statut |
|-------|:------:|:------:|:---------------:|:--------:|:------:|
| 3. Parrainage 1-tap (`wa.me` + credit a l'activation) | Faible-Moy | Acquisition ++ | Nul | **1** | Accepte + ameliore |
| 1. Rapport mensuel conformite PDF | Faible | Retention ++ | Nul | **2** | Accepte |
| 5. Onboarding < 5 min comme argument | Faible (+prereq structurel) | Acquisition + | Nul | **3** | Accepte (conditionne) |
| 2. Badge conforme verifiable/revocable | Faible→Moy | Acquisition + | Nul | 4 | Accepte + ameliore |
| 4. Stats publiques anonymisees | Faible | Credibilite + | Nul* | 5 | Accepte (reserves INPDP) |
| 6. Invitation avis unique | Tres faible | Acquisition ~ | Nul | 6 | Accepte |

## 9. Metriques nord (a instrumenter en amont)

Pour piloter la couche marketing sans naviguer a l'aveugle, ajouter au `KpiController` :
- **K-factor** (boucle de parrainage) et **CAC par canal** (parrainage vs paye vs organique/badge).
- **Taux de conversion trial→payant** (aujourd'hui bride — cf. Piste 5).
- **Retention M+1/M+3** correlee a la reception du rapport mensuel.
- **Attribution** signup → source (`referred_by`, backlink badge, page stats).

## 10. Principe directeur

Les 6 pistes reposent **exclusivement** sur des briques existantes (dompdf, coupons, scheduler, KPI, CMS Puck, sceau charte) et vivent en cron / page publique / ecran admin. **Aucune ne touche le wizard de check-in.** La seule dependance produit dure est la **monetisation self-service** (Piste 5) — a traiter en priorite dans le backlog (AUDIT-04, #13), faute de quoi l'acquisition amplifiee alimente un entonnoir qui fuit. Trois disciplines a tenir sur **toutes** les surfaces marketing : **zero emoji, agregats INPDP-safe, charte stricte**.
