# QA manuelle — widget embarqué Qayed

Ce script couvre les comportements du widget qui sont **difficiles ou
impossibles à couvrir par des tests automatisés** : application de la CSP
`frame-ancestors` par le navigateur, livraison réelle de `postMessage` à une
fenêtre parente, délégation de permission caméra dans une iframe cross-origin,
et rendu responsive. Les scénarios purement serveur (signature JWT,
idempotence, quota, etc.) sont couverts par la suite PHPUnit
(`backend/tests/Feature/PartnerApi/*`) et ne sont **pas** répétés ici.

À exécuter avant toute mise en production d'un changement touchant
`routes/widget.php`, `app/Http/Controllers/Widget/*`,
`PartnerWidgetFrameAncestors`, ou le bundle frontend du widget.

## Prérequis

- Une clé API en mode test (`qyd_test_...`) pour un partenaire de test, avec
  au moins une origine autorisée dans `api_partners.allowed_widget_origins`
  (ex. `http://localhost:5173`).
- Un établissement lié à ce partenaire (voir §1 du quickstart).
- Un endpoint webhook de test capable de logguer le corps brut + en-têtes reçus
  (ex. `https://webhook.site/...` ou un petit serveur local avec
  `verify-webhook.js`/`verify-webhook.php`).
- Un navigateur avec DevTools (Chrome/Firefox), et idéalement un second
  navigateur ou profil pour tester une origine non autorisée.

---

## 1. Origine d'iframe non autorisée → refus

**Pourquoi manuel** : la CSP `frame-ancestors` n'est appliquée que par le
navigateur (le serveur peut logger/rejeter côté JSON en défense en profondeur,
mais le blocage visuel de l'iframe elle-même ne peut pas être assert par un
test automatisé headless sans navigateur réel).

- [ ] Créer une session de fiche (`POST /v1/fiche-sessions`) et récupérer
      `widget_url`.
- [ ] Construire une page HTML locale servie depuis une origine **non**
      présente dans `allowed_widget_origins` du partenaire de test (ex. ouvrir
      le fichier directement en `file://`, ou le servir sur un port différent
      de celui autorisé).
- [ ] Y embarquer `<iframe src="{widget_url}">`.
- [ ] Vérifier dans la console navigateur qu'une erreur CSP
      `frame-ancestors` apparaît et que l'iframe reste vide/bloquée.
- [ ] Vérifier dans l'onglet Réseau que la réponse HTML de `/widget/fiche`
      porte bien l'en-tête `Content-Security-Policy: frame-ancestors ...`
      (n'incluant pas l'origine de test).
- [ ] Appeler directement `GET /widget/v1/bootstrap?token=...` avec un en-tête
      `Origin` non autorisé (via `curl -H "Origin: https://evil.example"`) et
      vérifier une réponse `403` avec `{"error":{"code":"frame_disallowed"}}`
      (défense en profondeur côté serveur, indépendante de la CSP).

## 2. Origine autorisée → chargement normal

- [ ] Répéter l'étape précédente depuis une origine présente dans
      `allowed_widget_origins`.
- [ ] Vérifier que l'iframe charge bien le formulaire (pas de blocage CSP en
      console).

## 3. Livraison `postMessage` à une fenêtre parente réelle

**Pourquoi manuel** : nécessite un vrai navigateur avec deux documents
(parent + iframe) sur deux origines différentes ; un test unitaire ne peut
simuler fidèlement le `event.origin` réel ni le timing de chargement croisé.

- [ ] Ouvrir `backend/public/docs/snippets/widget-integration.html` (ou une
      copie adaptée pointant vers l'environnement de test) dans un navigateur.
- [ ] Ouvrir la modale, dérouler le parcours complet jusqu'à la soumission.
- [ ] Confirmer dans la console du parent que l'événement `qayed:submitted`
      est bien reçu, avec `event.origin` égal à l'origine de Qayed, et que le
      payload contient `session_id`, `fiche_id`, `guest_count` corrects.
- [ ] Fermer le widget en cours de saisie (bouton retour/fermer côté widget,
      pas la croix de la page hôte) → confirmer la réception de
      `qayed:closed` avec le bon `session_id`, et que la modale hôte se ferme.
- [ ] Provoquer une erreur widget (ex. rouvrir un `widget_url` déjà expiré) →
      confirmer la réception de `qayed:error` avec un `code` cohérent avec
      `ErrorCodes` (ex. `session_expired`), et que la page hôte gère
      proprement le cas (message d'erreur affiché, modale refermée).
- [ ] Vérifier que la page hôte ignore silencieusement tout `postMessage`
      dont `event.origin` ne correspond pas à l'origine Qayed attendue
      (poster manuellement un message factice depuis la console du parent
      pour confirmer qu'il est bien filtré).

## 4. Délégation de permission caméra dans l'iframe

**Pourquoi manuel** : l'autorisation caméra dépend du prompt navigateur réel
et de l'attribut `allow="camera"` posé sur la balise `<iframe>` du **partenaire**
— aucun test automatisé ne peut simuler fidèlement l'invite navigateur ni
l'interaction utilisateur d'octroi de permission.

- [ ] Avec `allow="camera"` présent sur l'iframe hôte : ouvrir le widget sur
      l'étape de capture CIN/passeport, cliquer sur « scanner », vérifier que
      le prompt de permission caméra du navigateur apparaît **une seule fois**
      et que le flux vidéo s'affiche dans le cadre de visée à l'intérieur de
      l'iframe après acceptation.
- [ ] Retirer `allow="camera"` de la balise iframe hôte (copie de test) et
      confirmer que l'accès caméra est refusé silencieusement dans l'iframe
      (message d'erreur clair côté widget, pas d'écran blanc/crash), sans
      qu'aucun prompt navigateur n'apparaisse — la permission n'est même pas
      déléguée par la page hôte.
- [ ] Vérifier qu'un scan réussi déclenche bien `POST /widget/v1/scan` et que
      `GET /widget/v1/scan/{scanId}/status` finit par renvoyer
      `status` terminal avec `confidence`/`extracted` renseignés.

## 4bis. Lecture réelle du document (CIN et passeport)

**Pourquoi manuel** : la lecture passe par un modèle de vision (Claude) sur une
vraie photo — aucun test automatisé ne remplace un contrôle visuel sur des
pièces réelles (éclairage, angle, reflets, format CIN ancien/biométrique).
`WidgetVisionScanServiceTest` (PHPUnit) ne couvre que la fusion MRZ ↔ lecture
libre, jamais l'appel réseau lui-même — voir la discipline de test déjà
suivie pour `FicheScanCropper`.

- [ ] `OCR_DRIVER` reste sur son défaut (`mock`) — n'affecte pas ce chemin,
      voir `config/ocr.php` (`widget_vision` est un bloc séparé). Vérifier que
      `WIDGET_SCAN_AI` n'est pas à `false` et qu'`ANTHROPIC_API_KEY` est bien
      définie sur l'environnement testé.
- [ ] Bouton « Scanner CIN » : photographier une vraie CIN tunisienne
      (légale ou biométrique) avec un bon éclairage. Vérifier que
      prénom/nom/date de naissance/numéro sont correctement prérempli dans le
      formulaire (comparer à l'œil avec la pièce), et que `document_type`
      vaut bien « Carte d'identité ».
- [ ] Bouton « Scanner passeport » : vérifier que le cadre de visée affiche
      bien la bande MRZ (pointillés) et photographier un vrai passeport ouvert
      à la page de données. Vérifier que les champs proviennent de la lecture
      MRZ déterministe (numéro de document, date de naissance, sexe,
      expiration cohérents avec le passeport), pas d'une simple lecture
      visuelle approximative.
- [ ] Photographier un document flou/mal cadré/sans pièce (ex. la table vide)
      → vérifier un message d'erreur clair (pas de blocage, pas de champs
      inventés) et que la saisie manuelle reste possible.
- [ ] Reprendre exactement la même photo une seconde fois (retake avec le
      même cadrage/lumière autant que possible, ou réutiliser le même fichier
      via « Importer une image ») → vérifier dans `Admin > Coûts IA` qu'un
      seul événement `cin_scan`/`passport_scan` a été facturé pour ce
      check-in (idempotence par hash, voir `uploadScan`).
- [ ] Vérifier dans `Admin > Coûts IA` que les événements de cette session
      apparaissent avec le bon `feature` (`cin_scan` ou `passport_scan` selon
      le bouton utilisé) et un coût non nul.

## 5. Responsive tablette / mobile

- [ ] Réduire le viewport à une largeur tablette (~768px) : vérifier que la
      modale et le formulaire restent utilisables sans scroll horizontal, que
      les boutons d'action restent atteignables sans être masqués par le
      clavier virtuel.
- [ ] Réduire à une largeur mobile (~375px) : mêmes vérifications, plus
      confirmer que le cadre de visée caméra occupe une portion raisonnable de
      l'écran et que le bouton de fermeture de la modale reste accessible.
- [ ] Tester en orientation portrait et paysage sur un appareil mobile réel
      (l'émulation DevTools ne reproduit pas fidèlement le comportement de la
      caméra ni du clavier virtuel).

## 6. Parcours complet en mode test (`qyd_test_...`)

**Objectif** : valider de bout en bout que le mode test est réellement
**isolé** (aucun effet de bord réel) avant tout test en production.

- [ ] Lier un établissement de test au partenaire de test :
      `POST /v1/establishment-links` avec un code de liaison généré côté
      établissement.
- [ ] Créer une session avec **3 voyageurs** pré-remplis :
      `POST /v1/fiche-sessions` (clé `qyd_test_...`), vérifier `201` et
      `mode: "create"`.
- [ ] Ouvrir `widget_url` retourné dans une origine autorisée.
- [ ] Vérifier au bootstrap (`GET /widget/v1/bootstrap`) que les 3 voyageurs
      apparaissent bien en pré-remplissage.
- [ ] Compléter les documents d'identité pour les 3 voyageurs (scan ou saisie
      manuelle) puis soumettre (`POST /widget/v1/submit`).
- [ ] Vérifier la réponse `{session_id, fiche_id, guest_count: 3}`.
- [ ] Vérifier sur votre endpoint de test que le webhook `fiche.submitted`
      arrive avec :
  - [ ] en-tête `Qayed-Signature` valide (vérifié avec
        `verify-webhook.js` ou `verify-webhook.php`) ;
  - [ ] `guest_count: 3` dans le payload ;
  - [ ] `fiche_id`/`session_id`/`booking_ref` cohérents avec la session créée.
- [ ] **Isolation mode test — vérifications négatives** :
  - [ ] Aucun message n'a été envoyé sur le relais WhatsApp réel pour ce
        check-in (vérifier l'absence de nouvelle ligne dans
        `whatsapp_send_log` pour ce `check_in_id`, ou l'absence d'envoi dans
        les logs du worker WhatsApp).
  - [ ] Le quota `checkins_per_month` de l'organisation n'a **pas** été
        décompté (comparer `PlanEntitlements::summary($org)` avant/après —
        visible via `GET /v1/establishments`, champ `quota.used`).
  - [ ] `GET /v1/fiches/{fiche_id}` renvoie bien la fiche (elle existe belle
        et bien en base), mais confirmez qu'aucune notification n'a été
        envoyée à un voyageur réel.
- [ ] Répéter l'appel `POST /v1/fiche-sessions` avec le **même**
      `(establishment_id, booking_ref)` avant expiration → vérifier `200`
      (pas `201`) et le même `session_id` (idempotence).
