# Passkeys (WebAuthn) — architecture, exploitation, déploiement

## Ce que c'est, et ce que ce n'est pas

Une passkey est une paire de clés cryptographiques générée par l'appareil de
l'utilisateur. La clé privée reste dans l'enclave sécurisée (Secure Enclave,
TPM, élément sécurisé Android, clé FIDO2) et n'en sort jamais. Le serveur ne
détient que la clé publique.

**Face ID n'est pas implémenté, et ne peut pas l'être.** Le code appelle
`navigator.credentials.create()` et `navigator.credentials.get()` ; c'est le
système d'exploitation qui décide quoi présenter :

| Plateforme | Ce que l'utilisateur voit |
|---|---|
| iPhone / iPad | Face ID ou Touch ID |
| Mac | Touch ID, ou déverrouillage par Apple Watch |
| Windows | Windows Hello (visage, empreinte, code PIN) |
| Android | empreinte, visage, schéma ou code |
| Ordinateur sans biométrie | clé de sécurité USB/NFC, ou téléphone en relais (QR) |

Le backend ne reçoit **jamais** de donnée biométrique : ni visage, ni
empreinte, ni code de l'appareil. Le seul témoin de la vérification est un bit
(`UV`) dans `authenticatorData`, signé par l'appareil. Aucune colonne de la
base ne pourrait accueillir autre chose — voir le schéma plus bas.

## Bibliothèque choisie

**`web-auth/webauthn-lib` 5.3** (MIT, PHP ≥ 8.2, projet `web-auth/`).

Pourquoi celle-là :

- C'est l'implémentation de référence de l'écosystème PHP, celle sur laquelle
  s'appuient les bundles Symfony et les paquets Laravel tiers. Choisir un
  emballage Laravel aurait ajouté une couche opinionnée (ses propres routes,
  ses propres modèles, une session côté serveur) là où notre API est sans état
  et authentifiée par jeton Sanctum.
- Elle couvre la totalité des étapes de vérification exigées par la spec :
  challenge, origin, RP ID, signature, présence et vérification de
  l'utilisateur, cohérence des bits de sauvegarde, compteur anti-clonage.
- Elle est maintenue et suivie (`composer audit` en CI).

Installation (déjà faite) :

```bash
composer require web-auth/webauthn-lib:^5.3
```

Les composants Symfony `serializer`, `property-info` et `property-access` sont
épinglés en `^7.4` pour rester alignés sur ceux qu'embarque Laravel 12 ; sans
cette contrainte, Composer installe leur version 8 aux côtés d'un socle
Symfony 7.

Aucune extension PHP supplémentaire : `ext-json` et `ext-openssl` sont déjà là.

## Configuration

Tout est dans `config/webauthn.php`, alimenté par l'environnement. Deux valeurs
commandent la sécurité :

```dotenv
# Domaine du FRONTEND (celui qui appelle navigator.credentials.*), jamais celui
# de l'API. Frontend qayed.tn + API api.qayed.tn → « qayed.tn ».
WEBAUTHN_RP_ID=qayed.tn

# Origines exactes autorisées à présenter une réponse. C'est le rempart
# anti-hameçonnage. Aucun joker n'est accepté.
WEBAUTHN_ORIGINS=https://qayed.tn,https://www.qayed.tn
```

⚠️ **Changer `WEBAUTHN_RP_ID` invalide toutes les passkeys existantes.** Une
passkey est liée au domaine pour lequel elle a été créée ; c'est ce qui la rend
impossible à hameçonner. Les utilisateurs devraient toutes les recréer.

Les autres réglages (`WEBAUTHN_USER_VERIFICATION`, `WEBAUTHN_CHALLENGE_TTL`,
`WEBAUTHN_TIMEOUT`, `WEBAUTHN_MAX_CREDENTIALS`, `WEBAUTHN_RECOVERY_CODES`) sont
documentés dans `.env.example`.

`WEBAUTHN_USER_VERIFICATION=required` par défaut, et c'est délibéré : la
passkey remplace ici le couple mot de passe + TOTP. Sans vérification de
l'utilisateur, elle ne prouverait que la possession de l'appareil. L'abaisser à
`preferred` accepterait des clés de sécurité sans code — au prix de cette
garantie.

HTTPS est obligatoire (exigence de la spec, pas une option de configuration).
Seul `localhost` y échappe, pour le développement.

## Schéma

Migration `2026_08_13_000001_create_webauthn_tables`.

`users.webauthn_user_handle` — identifiant opaque de 32 octets, créé à la
première passkey. C'est lui qui est envoyé à l'authentificateur, et non
l'e-mail ni l'UUID interne : il est stocké en clair dans le trousseau de
l'appareil et ne doit rien révéler.

`webauthn_credentials` — une ligne par appareil enregistré :

| Colonne | Rôle |
|---|---|
| `user_id` | rattachement au COMPTE (jamais au rôle) |
| `credential_id` | identifiant du credential, base64url, unique globalement |
| `public_key` | clé **publique** COSE, base64url |
| `attestation_type`, `trust_path`, `aaguid` | provenance déclarée (attestation « none » ici) |
| `user_handle` | copie du handle du compte, exigée par la vérification |
| `transports` | `internal`, `hybrid`, `usb`, `nfc`, `ble` — indice pour le navigateur |
| `sign_count` | compteur anti-clonage |
| `backup_eligible`, `backed_up` | passkey synchronisée (iCloud/Google) ou non |
| `uv_initialized` | une vérification utilisateur a déjà eu lieu |
| `device_name`, `created_at`, `last_used_at`, `last_used_ip` | lisibilité et détection d'un accès non désiré |

L'unicité de `credential_id` passe par un index sur son empreinte MD5 : un
identifiant de credential peut atteindre 1023 octets, ce qui déborde la taille
de page d'un index b-tree Postgres.

`webauthn_challenges` — challenges émis par le serveur, à usage unique et
expirants. On y stocke les **options complètes** envoyées au navigateur, pas
seulement le challenge : la vérification rejoue exactement ce qui a été
demandé, RP ID et exigence de vérification compris.

`user_recovery_codes` — codes à usage unique, **hachés** (bcrypt).

Suppression du compte → suppression en cascade des trois.

## Endpoints

Publics (limiteur `throttle:webauthn`, 20/min) :

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/v1/auth/passkey/options` | challenge de connexion |
| POST | `/api/v1/auth/passkey/verify` | vérification → session complète |

`options` ne prend **aucun identifiant** et renvoie une liste
`allowCredentials` vide : la connexion repose sur les passkeys découvrables, et
l'endpoint ne peut donc pas servir d'oracle d'existence de compte.

Authentifiés (token complet exigé — une session `2fa-pending` est refusée) :

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/v1/auth/passkeys` | liste |
| POST | `/api/v1/auth/passkeys/options` | challenge d'enregistrement |
| POST | `/api/v1/auth/passkeys` | enregistrement après vérification |
| PATCH | `/api/v1/auth/passkeys/{id}` | renommer |
| DELETE | `/api/v1/auth/passkeys/{id}` | révoquer |
| GET | `/api/v1/auth/recovery-codes` | nombre de codes restants |
| POST | `/api/v1/auth/recovery-codes` | régénérer (exige le mot de passe actuel) |

Repli du second facteur, avec le token partiel de `/auth/login` :

| Méthode | Route | Rôle |
|---|---|---|
| POST | `/api/v1/auth/2fa/recovery` | code de récupération à la place du TOTP |

## Règle d'authentification

```
passkey enregistrée   → passkey seule            → session complète
pas de passkey        → mot de passe (+ TOTP si le compte l'exige)
passkey indisponible  → mot de passe, puis TOTP OU code de récupération
```

Une session ouverte par passkey porte la capacité `passkey-session` en plus de
`*`. Les middlewares `admin.2fa` et `authority.credential` l'acceptent comme
équivalent d'une TOTP configurée : une passkey vérifiée prouve déjà la
possession de l'appareil **et** la présence de son porteur. Exiger un TOTP
par-dessus serait un troisième facteur, pas un second.

Cette capacité ne donne **aucun droit supplémentaire**. Elle ne se teste pas
avec `can()` — Sanctum considère qu'un token `['*']` peut tout — mais par
lecture directe des capacités (`SessionIssuer::isPasskeySession`).

## Protection contre le rejeu

Le challenge est consommé par un `UPDATE` conditionnel unique **avant** la
vérification de signature : deux requêtes portant la même réponse ne peuvent
pas réussir toutes les deux, et une réponse capturée sur le réseau ne retrouve
aucun challenge ouvert. C'est la garantie que la signature seule ne donnerait
pas — une assertion valide reste rejouable tant que son challenge vit.

Le compteur `sign_count` est vérifié quand il progresse (beaucoup de passkeys
synchronisées le laissent à 0, ce que la spec autorise). Un compteur qui recule
signale un authentificateur cloné : la connexion est refusée.

## Récupération de compte

1. **Codes de récupération** — dix codes à usage unique, remis automatiquement
   à l'activation de la **première** passkey, affichés une seule fois. Ils
   remplacent le TOTP à l'étape de vérification, jamais le mot de passe : la
   liste seule ne permet pas d'entrer.
2. **Plusieurs passkeys** — un compte peut en enregistrer jusqu'à dix (iPhone,
   iPad, Mac, PC, clé de secours). Perdre un appareil n'enferme personne.
3. **Mot de passe** — jamais retiré. « Utiliser une autre méthode de
   connexion » reste vrai.
4. **Réinitialisation par e-mail** — le chemin existant, inchangé.

Un utilisateur ayant tout perdu passe par la réinitialisation de mot de passe,
puis révoque les passkeys de l'appareil disparu depuis Profil → Sécurité.

## Journalisation

Tout passe par `AuditLogger` (table `audit_logs`, avec IP et user-agent) :

`auth.passkey_registered`, `auth.passkey_renamed`, `auth.passkey_revoked`,
`auth.passkey_login`, `auth.passkey_login_failed` (avec la cause :
credential inconnu, compte inactif, échec de vérification),
`auth.passkey_registration_failed`, `auth.recovery_code_used`,
`auth.recovery_code_failed`, `auth.recovery_codes_regenerated`.

## Compatibilité

| Environnement | Passkey | Remplissage conditionnel |
|---|---|---|
| iPhone / iPad — Safari 16+ | oui (Face ID / Touch ID) | oui |
| iPhone / iPad — Chrome, Edge, Firefox | oui (moteur WebKit imposé par iOS) | oui |
| Android — Chrome | oui (biométrie / code) | oui |
| macOS — Safari 16+, Chrome, Edge | oui (Touch ID) | oui |
| Windows — Edge, Chrome | oui (Windows Hello) | oui |
| Firefox (bureau) | oui | selon la version — détecté, jamais supposé |
| Navigateur sans WebAuthn | non | non |

Le frontend interroge `isUserVerifyingPlatformAuthenticatorAvailable()` et
`isConditionalMediationAvailable()` plutôt que de deviner : quand une
fonctionnalité manque, le mot de passe est affiché sans que l'utilisateur ait
à comprendre pourquoi.

**PWA installée** : rien de particulier à faire. Le service worker est un
passe-plat sans cache, et la cérémonie WebAuthn ne transite pas par `fetch`.
Sur iOS, une PWA en mode `standalone` accède au même trousseau que Safari.

L'en-tête `Permissions-Policy` du frontend déclare explicitement
`publickey-credentials-get=(self)` et `publickey-credentials-create=(self)`.

## Tests

`tests/Feature/PasskeyAuthTest.php` — 30 tests, 213 assertions.

Les cérémonies passent par `tests/Support/VirtualAuthenticator`, un
authentificateur simulé qui détient une **vraie** paire de clés ES256 et
produit de **vraies** signatures (CBOR et COSE encodés à la main). Les cas
d'attaque échouent donc pour la bonne raison, et non parce qu'un raccourci de
test les rendrait impossibles : rejeu, challenge expiré, signature forgée,
mauvais origin, mauvais RP ID, compteur qui recule, absence de vérification
utilisateur, credential révoqué, credential d'un autre compte.

Sont également couverts : les quatre rôles, le changement de rôle, le compte
suspendu, l'expiration de session, la limite de passkeys, le repli TOTP, les
codes de récupération et leur usage unique.

```bash
php artisan test --filter=PasskeyAuthTest
```

Côté frontend, `src/lib/webauthn.test.ts` couvre l'encodage base64url, la
détection de plateforme (dont le piège iPad/Mac) et la classification des
erreurs.

Ce qui ne peut pas être testé automatiquement ici — le vrai geste biométrique
sur un vrai appareil — reste à vérifier à la main sur : iPhone + Safari,
iPhone + Chrome, Android + Chrome, Mac + Safari, Mac + Chrome, Windows + Edge,
Windows + Chrome.

## Déploiement

1. `composer install` (le conteneur le fait déjà au démarrage).
2. Poser `WEBAUTHN_RP_ID` et `WEBAUTHN_ORIGINS` sur le service backend **avant**
   la première utilisation. Sans elles, la configuration retombe sur
   `FRONTEND_URL`, ce qui fonctionne mais laisse le domaine implicite.
3. `php artisan migrate` (automatique au démarrage du conteneur).
4. Vérifier `php artisan config:cache` si la configuration est mise en cache.
5. Le frontend n'a besoin d'aucune variable supplémentaire.

Aucune coupure, aucune migration de données : les comptes existants continuent
de se connecter par mot de passe tant qu'ils n'ajoutent pas de passkey.

**Retour arrière** : retirer les routes suffit à désactiver la fonctionnalité
sans toucher aux comptes. Les tables peuvent rester en place — elles ne sont
lues que par ces routes.
