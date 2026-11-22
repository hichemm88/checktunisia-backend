# API v1.1 — Diagnostic : 11 sessions, 0 fiche soumise

Relevé en production le 22/11/2026 (lecture seule, `fiche_sessions`, `check_ins`,
`check_in_guests`, `document_scans`, `audit_logs`, `partner_webhook_deliveries`),
croisé avec l'historique git de Qayed (backend + frontend) et du dépôt Diarna
(`KASBAHOST-TEAM`). Heures en heure de Tunis (UTC+1) ; la base stocke l'UTC.

## Les 11 sessions

Toutes ont été créées le **16/09/2026**, le jour même de la mise en ligne de l'API v1,
avec la clé de test, sur Dar Zyne (5) et Dar Majoul (6). Aucune depuis.

| # | Créée | Widget ouvert (bootstrap) | Voyageurs | Scans | Issue |
|---|-------|---------------------------|-----------|-------|-------|
| 1 | 17:29 | non | 0 | 0 | expirée |
| 2 | 17:30 | non | 0 | 0 | expirée |
| 3 | 17:30 | non | 0 | 0 | expirée |
| 4 | 18:03 | non | 0 | 0 | expirée |
| 5 | 18:20 | oui (18:20) | 0 | 0 | expirée |
| 6 | 18:36 | oui (18:38) | 0 | 0 | expirée |
| 7 | 18:38 | oui (18:38) | 0 | 0 | expirée |
| 8 | 19:53 | oui (19:53) | 0 | 0 | expirée |
| 9 | 19:58 | oui (20:00) | 0 | 0 | expirée |
| 10 | 21:37 | oui (21:37) | 0 | 0 | expirée |
| 11 | 22:50 | oui (22:50) | 0 | 0 | expirée |

`partner_webhook_deliveries` : 11 `session.expired`, aucun `fiche.submitted` ni
`fiche.failed`. Aucun `guest.added` ni `scan.uploaded` n'a jamais atteint le serveur.
Les brouillons correspondants ont ensuite été supprimés (`check_in.deleted`).

## Cause, par tranche

1. **Sessions 1 à 4 — page du widget en erreur 500.** `GET /widget/fiche` plantait
   (type de retour erroné de `WidgetShellController`), corrigé par `b8f9dc3` à 18:15.
   Les quatre sessions sont antérieures au correctif : le bootstrap n'a jamais été appelé.
2. **Sessions 5 à 9 — tout ajout de voyageur refusé en 403.** Le navigateur envoie
   l'en-tête `Origin` (celle de la page du widget elle-même) sur tout POST, que le
   serveur ne comparait qu'aux origines du partenaire. Corrigé par `69ef4c5` à 20:19.
   Le widget s'ouvrait, mais rien ne pouvait être enregistré.
3. **Sessions 10 et 11 — ouvertes après tous les correctifs, puis abandonnées.**
   Aucune requête d'ajout ni de scan n'a atteint le serveur. À 22:56, Diarna masque le
   bouton « Fiche police » (`efe7a873`, demande produit : aucune maison liée, identifiants
   en attente), puis le gèle derrière `QAYED_UI_ENABLED` le 18/09 (`47b207d4`).
4. **Depuis le 16/09 — aucune session, parce que le bouton est masqué côté Diarna.**
   Ce n'est pas un échec : personne n'a eu de bouton à presser.

Il n'y a donc pas de cause bloquante active côté API aujourd'hui. Le rejeu complet est
fait sur une base locale (voir plus bas). Trois défauts, découverts en route, auraient
empêché la première vraie fiche ou son suivi :

- **Bundle du widget jamais republié.** `public/widget-assets/widget-main.js` est resté
  celui du commit initial (16:21). Les deux évolutions du widget de ce jour-là (choix
  CIN/passeport et vrai scan `d445ced`, messages d'erreur lisibles `e0d0891`) n'ont
  jamais été servies en production. **Corrigé** : bundle reconstruit et republié dans ce lot.
- **Documentation fausse sur les hôtes.** La doc et `widget-integration.html` annonçaient
  `https://qayed.tn` pour l'API et pour l'origine du widget. `qayed.tn` redirige (308) vers
  `www.qayed.tn` (application Vercel, qui interdit tout encadrement). Le widget est en
  réalité servi par **`https://api.qayed.tn`** : `widget_url` est construit sur `APP_URL`,
  qui vaut `https://api.qayed.tn` en production, et renvoie donc déjà l'hôte final, sans
  redirection. **Corrigé** : doc, spec et exemples alignés sur cet hôte. Diarna, qui a
  suivi la doc puis constaté la redirection en testant `qayed.tn` à la main, a figé une
  origine fausse (voir plus bas).
- **Aucun moyen de voir une fiche « soumise » en mode test.** Le webhook `fiche.submitted`
  n'est pas émis en mode test, et `GET /v1/fiche-sessions/...` ne renvoyait ni le nombre
  de voyageurs déclarés ni le nombre attendu. **Corrigé** : `guest_count` et
  `expected_guest_count` en lecture, documentés.

Défense en profondeur également posée : la page du widget ne porte plus
`X-Frame-Options: DENY` (le middleware global l'ajoutait). Les navigateurs actuels
donnaient déjà la priorité à `frame-ancestors`, d'où les sessions 5 à 11 bien ouvertes,
mais l'en-tête contredisait la politique du partenaire.

## Ce que Diarna doit changer

1. **Origine `postMessage`** — `src/components/QayedFicheButton.tsx` :
   `QAYED_WIDGET_ORIGIN = "https://www.qayed.tn"` est faux. Le widget est servi par
   `https://api.qayed.tn` : avec la valeur actuelle, `qayed:submitted`, `qayed:closed` et
   `qayed:error` sont tous ignorés en silence. Remplacer par `https://api.qayed.tn` (ou,
   plus robuste, par `new URL(widget_url).origin` mémorisé à l'ouverture).
2. **Réactiver le bouton** (`QAYED_UI_ENABLED`) une fois les maisons liées.
3. **Statut sans webhook en mode test** : relire `GET /v1/fiche-sessions/{session_id}` (ou
   `by-booking-ref`) à la fermeture du widget ; `status`, `guest_count`,
   `expected_guest_count` y figurent en test comme en live.
4. **Opérateur** : `GET /v1/operators` + `operator: { user_id, name }` à la création de
   session (voir `API-V1-1-RAPPORT.md` pour le détail des champs).
