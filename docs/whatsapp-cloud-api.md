# WhatsApp Cloud API — mise en service

Ce document couvre le canal de transmission **actif** des fiches de police.
Pour l'historique de l'extraction derrière une interface, voir
[canal-transmission.md](canal-transmission.md).

## Pourquoi cette migration

Le relais `whatsapp-web.js` reposait sur un numéro appairé à WhatsApp Web par
QR code. **Ce numéro a été banni par WhatsApp.** Dernière connexion :
17/08/2026. Depuis, les fiches s'accumulent en file sans partir — c'est-à-dire
que l'obligation légale du produit n'est plus remplie.

La Cloud API officielle remplace ce relais. Elle supprime le point unique de
défaillance (session, QR, volume Railway, worker Node) mais impose deux règles
de Meta qui changent la conception :

1. **Hors fenêtre de 24 h, seul un modèle approuvé passe.** Une fiche de police
   n'ouvre jamais de conversation : personne n'y répond. La fenêtre est donc
   toujours fermée, et tout part par modèle.
2. **Un modèle ne porte qu'un seul média.** Ce média est le PDF de la fiche,
   pièce d'identité comprise, téléversé via `/media` avant chaque envoi.

Le message final combine donc trois choses, chacune pour une raison distincte :

| Partie | Contenu | Pourquoi |
|---|---|---|
| En-tête | **PDF de la fiche**, pièce d'identité incluse | Rétablit la parité avec l'ancien relais ; une variable de modèle ne peut pas contenir de retour à la ligne, donc la fiche ne rentre dans aucune variable |
| Corps | Résumé en 9 variables | Le destinataire trie dans un fil unique : il doit savoir de quoi il s'agit sans ouvrir la pièce jointe |
| Bouton | « Consulter la fiche » | L'historique et le contexte que le PDF ne porte pas |

L'en-tête est **obligatoire** : un modèle approuvé avec en-tête média est
refusé (132000) si l'envoi ne fournit pas le média. Il n'y a donc pas d'envoi
« dégradé sans pièce jointe » — les fiches de test produisent un PDF factice
pour cette raison.

Un échec du téléversement `/media` est **temporaire**, jamais définitif : la
fiche est intacte, seule la tentative est perdue.

## Ce que le code fait

| Pièce | Rôle |
|---|---|
| `WhatsappCloudApi` | Seul endroit qui parle à Meta : URL, jeton, forme des réponses. |
| `WhatsappCloudErrors` | Traduit un code Meta en trois décisions : retenter ? alerter ? que dire ? |
| `FicheTemplate` | Fiche → variables du modèle, avec les contraintes que Meta impose aux variables. |
| `WhatsAppCloudChannel` | Adaptateur `DeliveryChannel` en push. Dernière barrière avant Meta. |
| `WhatsappSendingGuard` | Les quatre freins : coupe-circuit, bascule, débit, arriéré. |
| `WhatsappWebhookController` | Accusés de réception signés (`GET`/`POST /api/v1/webhooks/whatsapp`). |
| `FichePdf` | Rend la fiche en PDF (même vue Blade que l'export par email) et un exemplaire factice. |
| `FicheLinkController` | `GET /f/{token}` — cible stable du bouton, redirige (302). |
| `whatsapp:dispatch` | Vide la file — la Cloud API est en **push**, plus rien ne vient chercher les fiches. |

## Destinataires

**Une fiche part à PLUSIEURS destinataires**, comme avec le relais Web. La
règle n'a pas changé d'un iota :

- si l'établissement a des agents cochés dans `hotel_whatsapp_recipients`
  (Établissement > Destinataires WhatsApp, profils autorité avec
  `receives_whatsapp_fiches`), la fiche part à **chacun** ;
- sinon, repli sur le numéro global `WHATSAPP_RECIPIENT`.

Chaque couple (voyageur × destinataire) est une **ligne d'outbox distincte**,
donc son propre `wamid`, son propre statut, ses propres retries. Un
destinataire injoignable n'empêche pas les autres de recevoir la fiche.

Cette liste n'a jamais été en dur dans le code : rien à migrer.

## Le bouton « Consulter la fiche »

**L'URL de base d'un bouton de modèle est figée à l'approbation Meta.** La
changer impose de soumettre un nouveau modèle et d'attendre une nouvelle
validation — des jours, sur le canal légal du produit.

Le modèle pointe donc sur une route de redirection qui n'existe que pour
absorber les changements de destination :

```
https://api.qayed.tn/f/{{1}}          {{1}} = jeton public de l'envoi (ULID)
        └─ 302 ─> https://qayed.tn/authority/guests/{guest_id}
```

Le jour où une page « fiche » consultable par jeton signé existera, **seule
cette route change** : aucun nouveau modèle, aucune nouvelle validation, et
les messages déjà reçus par les policiers continuent de fonctionner.

Le jeton est un ULID (80 bits d'aléa, non énumérable) porté par la ligne
d'envoi — un jeton par destinataire, donc. Il **n'autorise rien** : la
destination exige une session du portail autorité. Un jeton qui donnerait
accès au contenu serait un lien vers des données personnelles envoyé en clair
par WhatsApp, sans expiration ni révocation.

Le jeton ne change pas au renvoi manuel : un lien stable qui se périmerait au
premier renvoi ne serait pas un lien stable.

### Le lien survit à la connexion

Les agents ont tous un compte, mais n'ouvrent pas encore le portail au
quotidien : le clic tombe presque toujours sur un navigateur non connecté.

La destination visée traverse donc l'authentification. Le garde de routage
redirige vers `/login?next=/authority/guests/{id}`, la page de connexion la
relaie à l'étape 2FA, et l'agent atterrit **sur la fiche**, pas sur l'accueil.
Sans cela le premier clic n'aboutissait jamais — et sur un levier d'adoption,
il n'y a pas de second clic.

Le paramètre est revalidé à chaque étape et n'accepte qu'un chemin interne :
une page de connexion des forces de l'ordre qui redirige où on lui dit serait
un tremplin de hameçonnage avec le bon nom de domaine.

## Connexion des agents par code WhatsApp (OTP)

### Le problème

Un agent autorité est enregistré dans le backoffice avec un nom et un numéro
WhatsApp. **Son adresse e-mail est fictive** — elle n'existe que pour remplir
la colonne `users.email`. Il ne peut donc ni activer un mot de passe, ni
recevoir de lien d'invitation, ni suivre une réinitialisation.

Jusqu'ici, le bouton « Consulter la fiche » l'envoyait vers un écran de
connexion qu'il **ne pouvait pas franchir**. Le seul facteur qu'il possède
réellement est le téléphone sur lequel la fiche vient d'arriver.

### Le parcours

```
message WhatsApp ──> /f/{token} ──302──> /login?next=/authority/guests/{id}
                                              │
                    « Recevoir un code sur WhatsApp »
                                              │
   POST /auth/otp/request {phone}  ──> 202 {"ok": true}   (toujours, quoi qu'il arrive)
                                              │
                            code à 6 chiffres, modèle qayed_otp
                                              │
   POST /auth/otp/verify {phone, code} ──> session 30 j, via = whatsapp_otp
                                              │
                                    la fiche visée s'ouvre
```

Le login mot de passe + 2FA et les passkeys sont **inchangés** : l'OTP s'ajoute
comme troisième chemin, il n'en remplace aucun.

### Ce qui rend ce chemin sûr

Un code à six chiffres envoyé sur demande est, en soi, un mécanisme faible :
10⁶ possibilités et un canal sur lequel on n'a aucune prise. Cinq règles le
rendent acceptable, et **elles se tiennent toutes** — en retirer une rend les
quatre autres décoratives.

1. **Aucun envoi vers un numéro saisi librement.** Le numéro doit déjà être
   enregistré par l'admin comme destinataire de fiches :
   `receives_whatsapp_fiches` *et* rattachement à au moins un établissement.
   C'est exactement l'ensemble des numéros vers lesquels une fiche part déjà.
   Le formulaire ne choisit pas une destination, il désigne un enregistrement
   existant.
2. **Réponse et délai identiques**, que le numéro soit éligible ou non :
   `202 {"ok": true}` dans tous les cas, y compris au-delà des limites de débit
   et pour un numéro verrouillé. Un plancher de 700 ms couvre l'oracle temporel
   — sans lui, un numéro inconnu répondrait en quelques millisecondes là où un
   numéro réel attend un appel à Meta, et la différence se mesure depuis
   n'importe quel navigateur.
3. **Trois essais par code**, puis verrouillage du numéro **15 minutes**. C'est
   ce qui ramène 10⁶ possibilités à trois tentatives. Un numéro verrouillé ne
   peut pas non plus redemander un code : sinon le verrou se contourne d'un
   clic sur « renvoyer ».
4. **Trois demandes par fenêtre de 10 minutes**, par numéro **et** par IP —
   deux limiteurs distincts. Un limiteur unique indexé sur le couple
   (numéro, IP) laisserait marteler un même numéro depuis autant d'adresses
   qu'on veut.
5. **Le code n'est stocké que haché** (même hachage que les mots de passe),
   n'est utilisable **qu'une fois**, et n'apparaît dans aucun journal : il
   n'existe en clair qu'entre sa génération et son départ chez Meta.

Aucun repli SMS ni e-mail : ce serait rouvrir la porte que la règle 1 ferme.

### La file OTP est séparée de celle des fiches

Un code **n'emprunte aucun** des garde-fous des fiches — seuil d'arriéré,
plafond quotidien, débit par minute, bascule `cutover_at`, disjoncteur.

Les garde-fous des fiches sont taillés pour un flux poussé vers des gens qui
n'ont rien demandé. Un code est l'exact opposé : il est demandé, à l'instant,
par la personne qui le reçoit, et il ne vaut rien cinq minutes plus tard. Les
mélanger aurait deux effets, tous deux mauvais :

- un arriéré de fiches — donc une **panne d'envoi** — fermerait aussi la porte
  du portail, au moment précis où un agent cherche à consulter une fiche qu'il
  n'a pas reçue ;
- un afflux de connexions consommerait le quota quotidien des fiches, qui est
  une obligation légale.

Ce que l'OTP conserve : le **coupe-circuit global**
(`WHATSAPP_SENDING_ENABLED`) et un **plafond horaire propre**
(`WHATSAPP_OTP_MAX_PER_HOUR`), sans lequel cette file serait le seul chemin non
borné vers Meta. L'envoi est **synchrone** : un code utile cinq minutes ne peut
pas attendre un ordonnanceur.

### La session, et sa révocation

Une connexion OTP ouvre une session de **30 jours**, marquée `via=whatsapp_otp`
(capacité `whatsapp-otp-session` sur le jeton Sanctum). Elle **vaut second
facteur** : exiger en plus une TOTP renverrait l'agent vers une page de
configuration qu'il ne peut pas atteindre, faute d'adresse e-mail réelle.

La session est **liée à l'appareil** par une empreinte grossière de
l'user-agent — famille de système et de navigateur, **sans numéro de version**.
S'accrocher à l'user-agent complet déconnecterait tout le monde à chaque mise à
jour de navigateur, c'est-à-dire tous les mois. Rejoué depuis un autre appareil,
le jeton est **détruit** (401 `OTP_DEVICE_MISMATCH`), pas seulement refusé.

Ce n'est pas une preuve cryptographique — un user-agent se forge. Ce n'est pas
ce qu'on lui demande : elle rend inopérant le vol de jeton **ordinaire**
(journal recopié, sauvegarde de navigateur, appareil prêté). Le facteur qui ne
se forge pas reste la possession du numéro au moment de la connexion.

Contrepartie des 30 jours, la **révocation** :

```
POST /api/v1/admin/authority-users/{id}/revoke-sessions
```

Toutes les sessions de l'agent tombent, sans distinction de facteur — au moment
où l'on révoque, on ne sait pas laquelle est compromise. Téléphone perdu,
mutation, numéro réattribué : sans ce bouton, l'accès survivrait un mois à
l'événement.

### Ce qui est journalisé

Dans le journal d'activité existant, **numéro masqué** (`216********6`), jamais
le code :

| Action | Quand |
|---|---|
| `authority.otp_requested` | Chaque demande : éligible ou non, envoyée ou non, motif. |
| `authority.otp_verify_failed` | Code faux, périmé, déjà utilisé, compte inutilisable. |
| `authority.otp_locked` | Verrouillage du numéro après trois essais. |
| `authority.otp_verify_locked` | Tentative sur un numéro verrouillé. |
| `authority.otp_session_opened` | Ouverture de session : durée, appareil. |
| `authority_user.sessions_revoked` | Révocation par un admin. |

### Le modèle `qayed_otp`

Catégorie **AUTHENTICATION**, créé par la même commande que le modèle de fiche :

```bash
php artisan whatsapp:templates --create
```

Meta **écrit le corps du message lui-même** pour cette catégorie : on ne fournit
ni texte ni exemple, seulement des interrupteurs
(`add_security_recommendation`, `code_expiration_minutes: 5`). Toute tentative
de personnaliser la formulation vaut un rejet — et c'est voulu de leur côté :
la forme d'un message de sécurité ne doit pas dépendre de l'expéditeur, sans
quoi l'hameçonnage devient indiscernable du légitime.

Le bouton est un **`COPY_CODE`** et non un `ONE_TAP` : l'autoremplissage en un
tap exige une application mobile signée, empreinte de certificat déclarée chez
Meta. Le portail est un site web ; « Copier le code » place le code dans le
presse-papiers, d'où la gestion du collage côté frontend.

À l'envoi, le code voyage **deux fois** : dans le corps et sur le bouton. Ce ne
sont pas deux paramètres mais deux emplacements du même, et Meta refuse le
message si l'un manque. `sub_type: url` sur un bouton COPY_CODE n'est pas une
erreur : c'est la forme qu'impose la Cloud API pour les boutons de modèle
d'authentification.

### `/f/{token}` : deux modes

`WHATSAPP_FICHE_LINK_MODE` vaut `portal` par défaut (redirection vers la fiche
derrière la connexion, `next` préservé). En `info`, la route sert une page
statique sans aucune donnée de fiche ni formulaire — utile pendant la fenêtre
où le modèle est approuvé, donc le bouton cliquable, mais le portail pas encore
ouvert.

**Le jeton est vérifié dans les deux modes** (404 si inconnu). C'est encore plus
nécessaire en mode `info`, où la page ne dépend pourtant pas de la fiche : sans
cette vérification, `/f/n-importe-quoi` servirait la même page officielle, et le
lien deviendrait un support d'hameçonnage au nom de Qayed.

## Les garde-fous, et pourquoi ils existent

Le numéro émetteur est **neuf** et la vérification d'entreprise Meta est encore
en cours. Un émetteur neuf qui expédie soudain des centaines de messages vers
des comptes qui ne lui ont jamais écrit est le profil exact que Meta bannit.

| Garde-fou | Variable | Effet |
|---|---|---|
| Coupe-circuit | `WHATSAPP_SENDING_ENABLED` | `false` → plus rien ne part. Relu à **chaque** envoi. |
| Bascule | `WHATSAPP_CLOUD_API_CUTOVER_AT` | Aucune fiche créée avant cet instant ne part. **Non définie = rien ne part du tout.** |
| Débit | `WHATSAPP_MAX_SENDS_PER_MINUTE` (20) | La file attend le créneau suivant. |
| Plafond | `WHATSAPP_MAX_SENDS_PER_DAY` (500) | Reprise le lendemain + alerte. |
| Arriéré | `WHATSAPP_BACKLOG_ALERT_THRESHOLD` (50) | Au-delà, **l'envoi automatique s'arrête** et réclame une décision humaine. |
| Qualité | codes Meta 131049 / 80007 | Pause globale de 15 min. |
| Débit horaire | `WHATSAPP_MAX_PER_HOUR` (30) | Fenêtre glissante, mesurée sur le journal — un worker qui redémarre ne remet pas le compteur à zéro. |
| Montée en charge | `WHATSAPP_WARMUP_*` (24 h, 6/h, 120 s) | Un numéro fraîchement appairé émet lentement : c'est le profil du compte jetable qui déclenche les sanctions. |
| Cadence | `WHATSAPP_MIN_INTERVAL_SECONDS` (45) + `WHATSAPP_INTERVAL_JITTER_RATIO` (0,4) | Un intervalle constant est une signature d'automate aussi lisible qu'une rafale. |
| Disjoncteur | `WHATSAPP_CIRCUIT_BREAKER_FAILURES` (5) | N refus d'affilée = restriction de compte. Coupe le relais EN BASE et alerte ; la reprise est un geste humain. |

Les cinq derniers viennent des garde-fous écrits après la **restriction du
17/08/2026**, quand le numéro a été suspendu 6 h pour « activité laissant
penser à du spam ». Ils étaient attachés au relais WhatsApp Web ; rien en eux
ne lui était propre, et une suspension sur le canal officiel coûterait le
numéro professionnel vérifié — autrement plus cher qu'une carte SIM.

Aucun de ces freins ne perd de fiche : tout reste en attente.

Le garde-fou d'arriéré mérite une phrase de plus. Un arriéré n'est pas un
volume de travail en retard, c'est **le symptôme d'une panne**. Le vider
automatiquement transforme une panne silencieuse en rafale vers des officiels —
c'est très exactement ce qui vient de coûter le numéro précédent. Le déblocage
est donc manuel, explicite et temporaire :

```bash
php artisan whatsapp:allow-backlog --minutes=60
```

## Variables d'environnement — référence complète

Toutes vivent dans `config/whatsapp.php`. Aucune n'est en dur dans le code,
aucune n'apparaît dans les journaux.

> ### Une seule famille de noms
>
> `WHATSAPP_` + le terme employé par la console Meta. **Il n'y a plus de repli
> sur d'anciens noms.**
>
> Le repli semblait prudent ; il ne l'était pas. Deux familles vivant en
> parallèle, ce sont deux endroits où poser un jeton, un seul qui compte, et
> aucun moyen de savoir lequel est lu — on croit avoir configuré le canal
> alors qu'on a rempli les variables mortes.
>
> **À supprimer de Railway** (les trois dernières n'étaient lues par rien) :
> `WHATSAPP_CLOUD_TOKEN`, `WHATSAPP_CLOUD_PHONE_NUMBER_ID`,
> `WHATSAPP_CLOUD_WABA_ID`, `WHATSAPP_CLOUD_TEMPLATE`,
> `WHATSAPP_CLOUD_TEMPLATE_LANG`.
>
> Pour vérifier ce que le code exige vraiment, sans grep :
> `php artisan whatsapp:check-config`.

### Obligatoires — sans elles, rien ne part

| Variable | Rôle |
|---|---|
| `WHATSAPP_POLICE_ENABLED` | Interrupteur général du module. `true`. |
| `WHATSAPP_CHANNEL` | **`cloud`. À poser EXPLICITEMENT** — voir l'encadré ci-dessous. |
| `WHATSAPP_API_TOKEN` | Jeton permanent d'utilisateur système. Secret. |
| `WHATSAPP_PHONE_NUMBER_ID` | Identifiant du numéro émetteur. |
| `WHATSAPP_WABA_ID` | Compte WhatsApp Business — gestion des modèles. |
| `WHATSAPP_APP_ID` | App Meta ; compose le jeton d'application du webhook. |
| `WHATSAPP_APP_SECRET` | Secret de l'app. **Signe le webhook** : sans lui, toute livraison est refusée. |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | Répond au défi de vérification de Meta. Secret partagé. |
| `WHATSAPP_CLOUD_API_CUTOVER_AT` | Instant de bascule, ISO 8601. **Non définie = aucun envoi.** |
| `WHATSAPP_SENDING_ENABLED` | Coupe-circuit. `true` pour émettre, `false` pour tout arrêter. |
| `WHATSAPP_RECIPIENT` | Numéro de repli, quand un établissement n'a aucun agent assigné. |

> ### `WHATSAPP_CHANNEL=cloud` — à poser à la main en production
>
> `cloud` est le défaut du code, mais **un `WHATSAPP_CHANNEL=web` résiduel
> dans Railway l'emporterait** : c'est la valeur que l'environnement portait
> jusqu'ici. Le canal retomberait sur le relais banni, qui accepte les fiches
> sans jamais les transmettre — un canal légal muet, sans alerte, exactement
> la situation qu'on sort de vivre.
>
> Vérifier la valeur effective, ne pas se fier au défaut :
> `GET /api/v1/admin/whatsapp/health` renvoie `data.channel`, qui doit valoir
> `whatsapp_cloud`.

### Garde-fous — valeurs par défaut sûres

| Variable | Défaut | Effet |
|---|---|---|
| `WHATSAPP_MAX_SENDS_PER_MINUTE` | `20` | Au-delà, la file attend le créneau suivant. |
| `WHATSAPP_MAX_SENDS_PER_DAY` | `500` | Atteint : reprise le lendemain + alerte admin. |
| `WHATSAPP_BACKLOG_ALERT_THRESHOLD` | `50` | Au-delà, **l'envoi automatique s'arrête** et réclame une décision humaine. |
| `WHATSAPP_QUALITY_PAUSE_MINUTES` | `15` | Pause globale après un code Meta de débit ou de qualité. |
| `WHATSAPP_MAX_PER_HOUR` | `30` | Plafond sur une heure glissante. |
| `WHATSAPP_MIN_INTERVAL_SECONDS` | `45` | Cadence plancher entre deux envois. |
| `WHATSAPP_INTERVAL_JITTER_RATIO` | `0.4` | Part d'aléa sur cette cadence. |
| `WHATSAPP_WARMUP_HOURS` | `24` | Durée de la montée en charge après appairage. |
| `WHATSAPP_WARMUP_MAX_PER_HOUR` | `6` | Plafond horaire pendant la montée en charge. |
| `WHATSAPP_WARMUP_MIN_INTERVAL_SECONDS` | `120` | Cadence pendant la montée en charge. |
| `WHATSAPP_CIRCUIT_BREAKER_FAILURES` | `5` | Refus consécutifs avant coupure du relais. |
| `WHATSAPP_BACKLOG_REPORT_PATH` | `storage/app/whatsapp/` | Destination du CSV des fiches annulées. |

Les deux réglages de l'OTP vivent **hors** de ce tableau : la file de connexion
est séparée de celle des fiches, et aucun garde-fou ci-dessus ne s'y applique.

| Variable | Défaut | Effet |
|---|---|---|
| `WHATSAPP_OTP_MAX_PER_HOUR` | `100` | Plafond horaire propre aux codes. Indépendant de `WHATSAPP_MAX_SENDS_PER_DAY` et de l'arriéré. |
| `WHATSAPP_OTP_SESSION_DAYS` | `30` | Durée de la session ouverte par code. |

Les bornes de **sécurité** de l'OTP n'ont volontairement **aucune variable
d'environnement** : validité 5 min, 3 essais, verrouillage 15 min, 3 demandes
par 10 min. Une durée de validité ou un nombre d'essais relâchable depuis
Railway serait une porte dérobée sur le portail autorité, ouvrable sans
relecture et sans trace dans le dépôt. Elles vivent dans `config/whatsapp.php`,
sous la clé `otp`.

### Modèle et lien

| Variable | Défaut | Effet |
|---|---|---|
| `WHATSAPP_TEMPLATE_NAME` | `fiche_police_nouvelle` | Nom du modèle approuvé (fiche). |
| `WHATSAPP_TEMPLATE_LANGUAGE` | `fr` | Code langue du modèle de fiche. |
| `WHATSAPP_OTP_TEMPLATE_NAME` | `qayed_otp` | Nom du modèle d'authentification. Même règle : doit correspondre EXACTEMENT au modèle approuvé. |
| `WHATSAPP_OTP_TEMPLATE_LANGUAGE` | `fr` | Code langue de ce modèle. |
| `WHATSAPP_FICHE_LINK_MODE` | `portal` | `portal` : redirection vers la fiche. `info` : page statique sans donnée ni formulaire. |
| `WHATSAPP_FICHE_URL_BASE` | `APP_URL` + `/f/` | Base du bouton. **Figée à l'approbation** : la changer impose un nouveau modèle. |
| `WHATSAPP_WEBHOOK_CALLBACK_URL` | `APP_URL` + `/api/v1/webhooks/whatsapp` | URL déclarée à Meta. |
| `WHATSAPP_API_VERSION` | `v21.0` | Version de l'API Graph. |
| `WHATSAPP_API_BASE_URL` | `https://graph.facebook.com` | Hôte Graph. Rarement modifié. |
| `WHATSAPP_API_TIMEOUT` | `30` | Délai d'appel, en secondes. |

`WHATSAPP_API_BASE_URL` et `WHATSAPP_API_TIMEOUT` sont les anciennes
`WHATSAPP_CLOUD_BASE_URL` et `_TIMEOUT` renommées ; les garde-fous
anti-restriction ci-dessus existaient déjà sur `main`. Les seules variables
réellement nouvelles sont celles de la connexion par code —
`WHATSAPP_OTP_TEMPLATE_NAME`, `WHATSAPP_OTP_TEMPLATE_LANGUAGE`,
`WHATSAPP_OTP_MAX_PER_HOUR`, `WHATSAPP_OTP_SESSION_DAYS` — et
`WHATSAPP_FICHE_LINK_MODE`. **Toutes ont un défaut sûr : ne rien poser
fonctionne.**

`FRONTEND_URL` et `APP_URL` doivent être justes : la première est la cible de
la redirection du bouton, la seconde compose la base du lien et l'URL du
webhook.

### Repli d'urgence, et héritage

| Variable | Effet |
|---|---|
| `WHATSAPP_PROVIDER=legacy` | Alias de `WHATSAPP_CHANNEL=web`. Le relais est banni : il ne transmettra rien. |
| `WHATSAPP_WORKER_SECRET`, `WHATSAPP_QR_URL`, `WHATSAPP_SESSION_VAULT_*` | Relais Web uniquement. Sans effet sur la Cloud API. |
| `WHATSAPP_CLOUD_*` | **Plus lues.** À supprimer de l'environnement — voir l'encadré ci-dessus. |

## Si une variable obligatoire manque

Le danger n'est pas la panne, c'est le **silence** : jusqu'ici, une variable
absente ne produisait aucune erreur — le canal se contentait de ne rien
envoyer, exactement comme un canal qui n'a rien à envoyer. C'est ainsi qu'un
arriéré de 715 fiches s'est constitué sans que personne ne le voie.

Quatre endroits le disent désormais, du plus tôt au plus tard :

| Où | Comportement | Déjà branché ? |
|---|---|---|
| **Pre-Deploy Command Railway** | **Annule la mise en production.** L'ancienne version continue de tourner. | ❌ **à poser dans les réglages Railway** — voir ci-dessous |
| `docker/start.sh` | Avertissement bruyant dans les journaux du conteneur, sans bloquer le démarrage. | ✅ dans le dépôt |
| Démarrage de l'application | Entrée de journal **critique**, relayée à Sentry. | ✅ |
| `whatsapp:dispatch`, `whatsapp:templates`, `whatsapp:configure-webhook` | Refusent de s'exécuter, avec la liste. | ✅ |
| `GET admin/whatsapp/health` | Champ `missing_config` : les noms des variables absentes, jamais leurs valeurs. | ✅ |

### La seule action manuelle : le Pre-Deploy Command

Ce dépôt n'a ni `railway.json`, ni `nixpacks.toml`, ni `Procfile` : la
commande de pré-déploiement se règle dans l'interface Railway, elle ne peut
pas être versionnée ici.

**Service `backend` → Settings → Deploy → Pre-Deploy Command :**

```bash
php artisan whatsapp:check-config
```

C'est le seul endroit où l'échec est à la fois *dur* et *sans danger* :
Railway exécute cette commande avant de basculer le trafic, et un code de
retour non nul **annule le déploiement** — la version précédente continue de
servir. Rien ne tombe.

La commande ne touche pas à la base : elle peut donc tourner avant les
migrations, sans ordre à respecter.

Pendant la mise en service, utiliser la variante stricte, qui exige en plus
`WHATSAPP_WABA_ID` et `WHATSAPP_APP_ID` :

```bash
php artisan whatsapp:check-config --admin
```

Ne pas la laisser en Pre-Deploy permanent : ces deux variables ne servent
qu'aux commandes d'administration, et un déploiement qui échouerait pour
elles bloquerait une correction urgente sans que l'envoi soit en cause.

### Pourquoi l'application ne s'arrête pas d'elle-même

`docker/start.sh` refuse de démarrer sans `APP_KEY` — l'application forgerait
sinon une nouvelle clé et perdrait les données chiffrées. Le canal WhatsApp
n'appelle pas la même réponse : une variable absente ne casse rien d'autre que
WhatsApp, alors que refuser de démarrer empêcherait aussi d'enregistrer les
check-in, de consulter le registre et de payer un abonnement.

Un hébergeur qui ne peut plus rien faire est un dommage plus grave que des
fiches qui attendent en file. Le déploiement, lui, peut échouer sans
conséquence pour personne : c'est là qu'on est intransigeant.

## Ordre de mise en service

Chaque étape suppose la précédente. Ne pas sauter la 3.

### 1. Déployer, tout verrouillé

```
WHATSAPP_SENDING_ENABLED=false
WHATSAPP_CLOUD_API_CUTOVER_AT=          # non définie
WHATSAPP_CHANNEL=cloud                  # voir ci-dessous
```

Aucun envoi n'est possible. C'est l'état correct pour un déploiement.

`cloud` est le défaut du code : la ligne n'est nécessaire que si
`WHATSAPP_CHANNEL=web` traîne encore dans l'environnement Railway, où elle
l'emporterait sur le défaut. **Vérifier explicitement cette variable** — un
canal resté sur `web` signifie un canal légal muet, sans que rien ne le
signale.

Vérifier immédiatement que le code voit bien vos variables :

```bash
php artisan whatsapp:check-config --admin
```

### 2. Créer le modèle et attendre l'approbation

```bash
php artisan whatsapp:templates --create
```

La commande téléverse d'abord un PDF d'exemple via l'API Resumable Upload
(`/{APP_ID}/uploads`) pour obtenir le `header_handle` : **sans lui, Meta refuse
la création d'un modèle à en-tête DOCUMENT.** Les données de l'exemple sont
fictives — aucune fiche réelle ne part chez Meta.

Pour interroger un autre compte WhatsApp Business (deux WABA coexistent
pendant une bascule) : `php artisan whatsapp:templates <waba_id>`.

Puis, pour suivre (l'approbation est asynchrone, de quelques minutes à quelques
jours — et hors de notre contrôle) :

```bash
php artisan whatsapp:templates
```

**Ne pas continuer tant que le statut n'est pas `APPROVED`.**

Si le modèle revient `REJECTED` — le corps compte 9 variables, ce que Meta
refuse parfois pour un ratio texte-variables jugé trop faible — le plan de
repli est prêt dans [whatsapp-template-repli.md](whatsapp-template-repli.md) :
lire d'abord le motif, puis fusionner les variables sans rien perdre. Ne PAS
relancer `--create` à l'identique.

### 3. Neutraliser l'arriéré — ne jamais l'envoyer

Environ 715 fiches en attente datent du bannissement. Les séjours concernés
sont terminés ; les envoyer n'apporterait rien et ferait bannir le nouveau
numéro.

```bash
php artisan whatsapp:cancel-backlog                 # dry-run : décompte par établissement et par jour
php artisan whatsapp:cancel-backlog --apply         # annule + écrit le rapport CSV
```

Le dry-run est le défaut. Rien n'est supprimé : les fiches passent en
« annulé » avec le motif `pre_cutover_backlog` et restent consultables dans
Administration > WhatsApp (compteur « Annulés »). Le rapport CSV est déposé
dans `storage/app/whatsapp/` (hors dépôt) pour un traitement manuel éventuel.

La commande est idempotente : relancée, elle ne trouve plus rien.

### 4. Armer la bascule

```
WHATSAPP_CLOUD_API_CUTOVER_AT=2026-08-31T23:00:00+01:00   # l'instant présent
```

À partir de là, seules les fiches **créées après** cet instant peuvent partir —
y compris via un renvoi manuel.

### 5. Enregistrer le webhook

L'endpoint doit être **déployé et joignable** : Meta appelle le `GET` de
vérification pendant l'exécution.

```bash
php artisan whatsapp:configure-webhook
```

La commande enchaîne les deux abonnements qu'on confond souvent — et c'est
cette confusion qui fait qu'un webhook « configuré » ne reçoit rien :

1. l'**app** déclare son URL de rappel et s'abonne à `whatsapp_business_account` ;
2. le **WABA** s'abonne à l'app.

Puis elle relit l'état chez Meta. Vérifier dans les logs backend que le `GET` a
bien été reçu.

### 6. Ouvrir le robinet et tester

```
WHATSAPP_SENDING_ENABLED=true
```

Faire un check-in de test vers **un numéro de test — pas un officiel**, et
vérifier :

- la ligne passe `sent` avec un `wamid` ;
- le webhook fait passer `delivery_status` à `delivered` puis `read`.

### 7. Bascule réelle

Seulement maintenant, remettre la vraie liste de destinataires.

## Retour arrière

```
WHATSAPP_SENDING_ENABLED=false
```

Effet immédiat, rien n'est perdu. Le retour à l'ancien relais
(`WHATSAPP_CHANNEL=web` ou `WHATSAPP_PROVIDER=legacy`) reste câblé, mais **il
ne transmettra rien** : le numéro est banni. Il n'est conservé que pour ne pas
supprimer du code encore utile au diagnostic.

## Ce qui reste à faire

Honnêtement :

- **Il n'existe pas encore de page « fiche » consultable sans compte.**
  `/f/{token}` redirige vers le portail autorité, qui exige une session. Les
  agents enregistrés peuvent désormais l'ouvrir par code WhatsApp ; les
  destinataires du **repli global** (numéro `WHATSAPP_RECIPIENT`, qui n'est pas
  un profil autorité) reçoivent toujours un bouton qu'ils ne peuvent pas
  franchir. Le jour où la page existera, seule la destination de
  `FicheLinkController` change — **pas le modèle**, c'est précisément ce que
  cette indirection achète.
- **La connexion par code ne couvre que les agents rattachés à un
  établissement.** C'est délibéré (règle 1 plus haut) : un profil créé avec un
  numéro mais coché par aucun établissement ne reçoit aucune fiche, il ne doit
  donc pas recevoir de code. Un agent qui « ne reçoit rien » a d'abord un
  problème de rattachement, pas de connexion.
- **Le jeton n'expire pas et ne se révoque pas.** Il ne donne accès à rien par
  lui-même, donc ce n'est pas urgent ; ce le deviendra si la page par jeton
  signé voit le jour.
- **Le code du relais Web n'est pas supprimé** (worker Node, coffre de session,
  routes internes). Nettoyage dans une PR ultérieure, une fois la Cloud API
  stabilisée en production.
