# AUDIT 03 — Couche marketing sans complexite

> **Skill mobilise : `architecture` / `system-design`** (evaluation d'impact vs cout d'integration ; non installe — methodologie manuelle).
> Contrainte absolue : **rien ne s'ajoute au parcours quotidien de l'operateur.** Le marketing vit en peripherie (crons, pages publiques, admin), jamais dans le wizard de check-in.

## Grille de decision

Chaque piste est notee : **Effort** (dev), **Impact** (acquisition/retention), **Risque de complexification du parcours operateur** (doit etre nul/quasi-nul), **Verdict**.

### 1. Rapport mensuel automatique de conformite (PDF brande قيد)
- **Verdict : ACCEPTE — priorite haute.** C'est la meilleure piste : zero action operateur, demonstration de valeur automatique.
- **Reutilisation existante** : `barryvdh/laravel-dompdf` (deja utilise pour factures), le scheduler (`routes/console.php`), `SystemMailer`/Resend, `KpiController` (fiches transmises, temps economise deja calculables), la charte PDF. On duplique le pattern `ReportNextCheckin` / dunning.
- **Effort : Faible** (1 commande planifiee `reports:monthly-compliance` + 1 template PDF + 1 template email). 
- **Impact : Retention fort** (le gerant voit la valeur chaque mois → reduit le churn), acquisition indirecte (PDF partageable).
- **Risque parcours : NUL.** Cron mensuel, l'operateur ne fait rien.
- **Garde-fous** : chiffres honnetes (pas de « X min economisees » invente — le derive d'une base mesurable : nb fiches × delta temps papier documente) ; **aucun emoji** (respecter regle 4, contrairement au bug actuel `ReportNextCheckin.php`) ; INPDP : agreger, jamais de PII voyageur dans le rapport.

### 2. Badge « Etablissement conforme Qayed » (widget web + certificat imprimable)
- **Verdict : ACCEPTE — priorite moyenne, en 2 temps.**
- **Certificat imprimable** (PDF/affichette a la reception) : trivial, reutilise dompdf + le sceau `QayedStamp` (−6°). Effort faible.
- **Widget web embarquable** (badge sur le site de la guesthouse) : sert de preuve sociale + backlink SEO. Effort moyen (endpoint public signe + snippet). 
- **Impact : Acquisition organique par les pairs** (une guesthouse voit le badge d'une autre) — mecanisme lent mais compose.
- **Risque parcours : NUL** (genere depuis l'admin/settings, hors flux quotidien).
- **Amelioration** : rendre le badge **verifiable** (URL `/verify/{orgToken}` → page publique « conforme au JJ/MM ») pour eviter les faux badges ; token revocable si l'abonnement lapse (coherent avec la machine a etats).

### 3. Parrainage a un tap (lien WhatsApp pre-redige, mois offert des deux cotes)
- **Verdict : ACCEPTE — priorite haute (meilleur ROI acquisition).**
- **Reutilisation** : le systeme de coupons existe deja (`Coupon`, `CouponRedemption`, `CouponController`) ; la charte WhatsApp aussi. Le « lien WhatsApp pre-redige » est un simple `wa.me/?text=` cote client — **pas** besoin du relais Puppeteer (donc pas de charge sur l'infra fragile).
- **Effort : Faible-Moyen** (generer un code de parrainage par org, page de redemption, credit mois des deux cotes via coupon). 
- **Impact : Acquisition fort + retention** (le parrain reste pour son mois offert).
- **Risque parcours : NUL** (bouton « Parrainer » dans settings, hors wizard).
- **Garde-fou** : texte pre-redige **sans emoji** ; anti-abus (1 credit par nouvel etablissement reellement active, pas au signup — s'appuyer sur « 1er check-in » comme evenement d'activation deja tracke par `KpiController`).

### 4. Page publique de statistiques anonymisees (preuve sociale, dossier Startup Act)
- **Verdict : ACCEPTE avec reserves — priorite moyenne.**
- **Reutilisation** : CMS public (Puck) + un endpoint public agregeant des KPI **anonymises** (total fiches traitees, temps moyen). 
- **Effort : Faible.**
- **Impact : Acquisition/credibilite** (autorites, Startup Act, presse).
- **Risque parcours : NUL.**
- **Reserves fortes (INPDP + securite biface)** : n'exposer QUE des agregats non reversibles (jamais de granularite par etablissement/ville qui reidentifierait un petit hotel ; seuil minimal d'agregation) ; cache/CDN pour eviter que la page devienne un oracle temps-reel sur l'activite frontaliere. **Ne jamais** deriver de la watchlist/autorite.

### 5. Onboarding self-service < 5 min (« operationnel avant la fin de votre cafe »)
- **Verdict : ACCEPTE — c'est deja presque vrai, en faire l'argument n°1.**
- **Etat reel** : le provisioning trial est deja self-service ~2-3 min, transaction unique (`PublicRegistrationController::register`), sans verif email bloquante, login immediat. Le wizard signup fait 4 etapes.
- **Effort : Faible** (surtout marketing + 1-2 frictions a lever : politique mot de passe stricte min 12 en 1 seul champ, creation chambres deportee au post-login).
- **Impact : Acquisition** (reduction du taux d'abandon signup).
- **Risque parcours : NUL** (ameliore le parcours existant, n'en ajoute pas).
- **Prerequis produit critique** : brancher la **conversion trial→payant en self-service** (aujourd'hui impossible sans intervention admin — voir AUDIT-02/04). Sinon l'onboarding rapide alimente un entonnoir qui fuit a la monetisation.

### 6. Temoignage integre (1 invitation Google discrete apres 30 j sans incident, jamais de relance)
- **Verdict : ACCEPTE — priorite basse, faible effort.**
- **Reutilisation** : `activation` (1er check-in) + « 30 j sans incident » deja derivables des KPI/audit ; 1 email `SystemMailer`.
- **Effort : Tres faible** (1 cron `reviews:invite-once` + flag `metadata.review_invited` idempotent, comme le pattern dunning `dunning_sent`).
- **Impact : Acquisition (SEO/reputation locale)** modere.
- **Risque parcours : NUL** (1 email, pas de relance — respecter strictement le « une seule invitation »).
- **Garde-fou** : sans emoji ; opt-out honore ; ne jamais declencher si un incident (echec paiement, sub suspendue, ticket) est ouvert.

## Recapitulatif priorise (marketing)

| Piste | Effort | Impact | Risque parcours | Priorite |
|-------|:------:|:------:|:---------------:|:--------:|
| 3. Parrainage 1-tap (coupons + wa.me) | Faible-Moy | Acquisition ++ | Nul | **1** |
| 1. Rapport mensuel conformite PDF | Faible | Retention ++ | Nul | **2** |
| 5. Onboarding < 5 min comme argument | Faible | Acquisition + | Nul | **3** (dep. conversion self-service) |
| 2. Badge conforme (certificat puis widget) | Faible→Moy | Acquisition + | Nul | 4 |
| 4. Stats publiques anonymisees | Faible | Credibilite + | Nul* | 5 (*reserves INPDP) |
| 6. Invitation avis unique | Tres faible | Acquisition ~ | Nul | 6 |

**Principe directeur** : les 6 pistes s'appuient sur des briques existantes (dompdf, coupons, scheduler, KPI, CMS, sceau charte) et vivent en cron/page/admin. Aucune ne touche le wizard de check-in. La seule dependance produit dure est la **monetisation self-service** (piste 5) — a traiter en priorite dans le backlog (AUDIT-04).
