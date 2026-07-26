# SYNTHESE — Audit 360 Qayed (juillet 2026)

**Verdict general.** Produit mature et bien architecture : biface operateur/autorite reellement etanche, RBAC spatie coherent, dunning automatique complet, i18n fr/en/ar quasi-parite, charte respectee. La regle « acces multi-proprietes jamais conditionne par le role » est **respectee partout** (deep-links inclus). Les faiblesses ne sont pas structurelles mais concentrees sur : **conformite INPDP a un endroit precis, friction du check-in (regle ≤3 taps non tenue), monetisation self-service manquante, et fragilite datee du relais WhatsApp.**

**3 points a corriger d'urgence (conformite/argent) :**
1. **INPDP — logs de PII** : noms, numeros CIN, dates de naissance et MRZ passeport sont ecrits en clair dans la console navigateur en production (`cinScanner.ts`, `mrzScanner.ts`), sans garde. Les endpoints Vision, eux, sont propres (zero persistance).
2. **INPDP — image document** : le relais WhatsApp (« MODULE PROVISOIRE ») **persiste l'image CIN/passeport 24 h par defaut**, meme sur echec du health-check et meme si l'OCR echoue. La contrainte « zero persistance image » a donc une exception activee par defaut — a surfacer aux auditeurs.
3. **Monetisation** : le trial self-service fonctionne, mais **aucun chemin self-service trial→payant** — un admin Qayed doit creer une facture a la main. L'entonnoir d'acquisition fuit a la conversion.

**Note skills (Etape 0).** Les skills demandes (`code-review`, `tech-debt`, `architecture`, `system-design`, `testing-strategy`, `documentation`, `debug`) ne sont **pas installes** dans cet environnement (skills disponibles : pdf, docx, xlsx, pptx, dataviz, security-review, etc.). Leur *methodologie* a ete appliquee manuellement via 6 agents d'exploration a preuve (chaque constat cite en `fichier:ligne`). Chaque section d'audit indique le skill correspondant.

## Top 10 des actions par ROI

| # | Action | ROI | Effort | Livrable |
|---|--------|:---:|:------:|----------|
| 1 | Gater/retirer les `console.log` de PII (CIN/MRZ) en prod | ⭐⭐⭐⭐⭐ | < 1 j | Conformite INPDP |
| 2 | Corriger le bug `sex` (422 mute sur scan CIN propre) | ⭐⭐⭐⭐⭐ | < 1 j | Friction quotidienne eliminee |
| 3 | Retirer les emoji de l'email `ReportNextCheckin` + ecrire `cancelled_at` a l'annulation | ⭐⭐⭐⭐⭐ | < 1 j | Regle 4 + KPI churn repare |
| 4 | Nettoyage cruft (junk files, `.phpunit.result.cache`, `backend-handoff/`, READMEs, `.env.example` front) | ⭐⭐⭐⭐⭐ | < 1 j | Hygiene repo + onboarding dev |
| 5 | CI backend (phpunit + pint) — le repo le mieux teste n'a aucun gate | ⭐⭐⭐⭐ | < 1 sem | Anti-regression |
| 6 | Parrainage 1-tap (coupons existants + `wa.me`, hors relais Puppeteer) | ⭐⭐⭐⭐ | < 1 sem | Croissance acquisition |
| 7 | Rapport mensuel de conformite PDF brande (cron + dompdf) | ⭐⭐⭐⭐ | < 1 sem | Retention, zero action operateur |
| 8 | Wizard « scan-first » + chambre par defaut (viser ≤3 taps) | ⭐⭐⭐⭐ | ~1 sem | Regle d'or n°1 |
| 9 | Webhook + reconciliation Flouci (paiement fantome) et vue admin « client 360 » | ⭐⭐⭐ | ~1 sem | Revenu + ops |
| 10 | Self-service checkout trial→payant + etat `past_due` | ⭐⭐⭐ | > 1 sem | Monetisation (debloque piste onboarding) |

**Au-dela du top 10 (structurel)** : cabler le prefill client recurrent (aujourd'hui code mort → double-saisie du client fidele) ; offline-first terrain (regle 5 non tenue) ; tests CIN/2FA/Flouci ; **decision WhatsApp avant le 2026-09-10** (le pin de version WA Web expire → panne datee) via une interface `WhatsappTransport` ouvrant sur l'API Cloud officielle ; fondations d'i18n MENA (registre de formats de piece, couche `Money`, modele `Jurisdiction`) posees comme refactors invisibles pour l'operateur tunisien.

**Simplicite par la soustraction** : supprimer `backend-handoff/` (doublon derive), archiver `EXPLORATION-CIN.md`, fusionner l'etape « booking » dans le flux scan-first, et soit cabler soit retirer le lookup client recurrent (qui ajoute aujourd'hui jusqu'a 3 s de latence pour rien).

---
*Livrables complets : AUDIT-01-CARTOGRAPHIE.md, AUDIT-02-FRICTION.md, AUDIT-03-MARKETING.md, AUDIT-04-ROADMAP.md. Audit uniquement — aucun code de production modifie. Les Quick Wins (top 1-4) sont prets a etre implementes apres votre validation.*
