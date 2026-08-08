# Persistance de la session WhatsApp

## Le symptôme

« La session WhatsApp se déconnecte à chaque déploiement du backend. »

## La cause

Elle n'était pas dans le stockage. Elle était dans le worker lui-même.

`whatsapp-service/index.js` armait au démarrage un watchdog de 120 secondes :

```js
// ANCIEN CODE
const watchdog = setTimeout(() => {
  if (!ready && !state.qrDataUrl) {
    wipeSession(SESSION_PATH);   // ← efface le volume
    process.exit(1);
  }
}, 120000);
```

Si, dans les deux minutes suivant le démarrage, ni QR ni connexion n'étaient obtenus, **le worker effaçait lui-même la session sur le volume**, puis redémarrait et affichait un QR.

Trois faits qui, réunis, rendaient l'incident quasi systématique :

1. **Un démarrage à froid dépasse régulièrement 120 s.** Conteneur neuf, Chromium à lancer, profil de plusieurs centaines de méga-octets à ouvrir depuis un volume réseau, puis WhatsApp Web à charger et à resynchroniser.
2. **`authenticated` ne désarmait pas le compte à rebours.** Seuls `qr` et `ready` le faisaient. Une session en cours de restauration — donc déjà authentifiée — restait exposée pendant tout l'intervalle entre les deux événements. C'est précisément la fenêtre la plus longue.
3. **Les deux services Railway se construisent depuis le même dépôt.** Un `git push` sur `main` redéploie l'API Laravel *et* le worker WhatsApp. Chaque livraison du backend rejouait donc ce tirage au sort, d'où la corrélation observée avec les déploiements.

Le volume, lui, fonctionnait. La session n'était pas perdue par le stockage : elle était détruite par le code censé la protéger.

## Ce qui a été corrigé

### 1. Le watchdog n'efface plus

Délai porté à 7 minutes, désarmé par **tout** signe de vie (`qr`, `authenticated`, `loading_screen`, `ready`), et un dépassement ne fait plus que **redémarrer le conteneur** — la session reste sur le volume.

Effacer reste possible, mais c'est devenu une décision humaine explicite : `WHATSAPP_ALLOW_SESSION_WIPE=1`. À n'utiliser que pour un ré-appairage volontaire, et à retirer aussitôt.

### 2. Arrêt propre

Railway envoie `SIGTERM` avant de couper le conteneur. Sans gestionnaire, Node s'arrêtait net et Chromium était tué au milieu de ses écritures, laissant un profil (LevelDB, IndexedDB) potentiellement incohérent — ce qui rallongeait le démarrage suivant, donc augmentait les chances de déclencher le watchdog. La boucle était bouclée.

Le worker ferme désormais Chromium proprement, puis archive le profil **au repos** — la meilleure copie possible.

### 3. Le volume n'est plus l'exemplaire unique

Un volume Railway est attaché à **une instance**. Il survit à un redéploiement ordinaire, mais pas à une recréation du service, à une migration de région, ni à une fausse manœuvre. Le canal de transmission légal du produit reposait donc sur une copie unique et non sauvegardée d'un secret qu'on ne peut reconstituer qu'en allant re-scanner un QR sur le téléphone émetteur.

La session est désormais archivée dans le **stockage objet déjà utilisé pour les sauvegardes de base**, chiffrée avec la **clé existante du `BackupKeyring`** (XChaCha20-Poly1305). Aucun nouveau fournisseur, aucun nouveau secret, aucune nouvelle clé à gérer.

```
worker Node                          backend Laravel                stockage objet
───────────                          ───────────────                ──────────────
au démarrage :
  volume a une session ?  ── oui ──▶ (le coffre n'est pas sollicité)
              │
             non
              │
              └──▶ GET  /internal/whatsapp/session-archive ──▶ déchiffre ──▶ R2

après appairage, toutes les 6 h, et à l'arrêt :
       tar.gz du profil ──▶ POST /internal/whatsapp/session-archive ──▶ chiffre ──▶ R2
```

## Les garde-fous

Ils comptent plus que le mécanisme, parce que la panne qu'ils préviennent est irréversible.

| Risque | Garde-fou |
|---|---|
| Un worker démarré **sans volume** archive du vide par-dessus les credentials | Le dépôt est refusé côté worker si le profil local n'a pas ses magasins (`IndexedDB` **et** `Local Storage`), et refusé côté backend sous un plancher de taille |
| Un **transfert tronqué** remplace une archive saine | Empreinte SHA-256 annoncée par le worker et vérifiée avant tout stockage |
| Un **dépôt valide mais inutilisable** (profil pris au mauvais moment) | La version précédente est conservée sous `…​.previous` |
| Le coffre **restaure une session périmée** par-dessus une session locale valide | Le coffre n'est sollicité que si le disque local n'a **rien** d'exploitable |
| Un **profil fantôme** (né d'un QR jamais scanné) passe pour une session et masque la copie saine | Marqueur d'appairage `.qayed-paired.json`, posé uniquement sur `ready`. Voir ci-dessous |
| Un profil restauré se **mélange** au profil déjà présent | L'archive est extraite à l'écart et **validée** d'abord ; l'ancien profil n'est écarté (sous `session.orphan`, conservé) qu'une fois le remplaçant prouvé bon, et remis en place si le basculement échoue |
| Le coffre est **injoignable au démarrage** (backend en plein redéploiement — le cas normal) | Trois tentatives, puis démarrage avec ce qu'on a. Rien n'est jamais effacé |
| La session finit **en clair** dans le stockage objet | Sans clé de chiffrement, le coffre refuse de stocker plutôt que de déposer en clair |
| Un secret **fuit dans les journaux** | Les traces ne portent que des tailles, des préfixes d'empreinte et des identifiants de clé. Testé |

## Le marqueur d'appairage

Constaté en production le 8 août 2026, en observant le premier démarrage du
nouveau code : **un démarrage qui affiche un QR fabrique lui aussi un profil
Chromium complet.** IndexedDB, Local Storage, 112 Mo de fichiers parfaitement
formés — appairés à personne.

La seule présence des magasins ne distingue donc pas une session valide d'un
profil né d'un QR jamais scanné. Sans correctif, ce profil fantôme aurait
compté comme « session locale exploitable » et masqué la copie saine du coffre
à chaque démarrage suivant. Le filet aurait été là, et on ne serait jamais
tombé dedans.

Le worker écrit donc `<session>/.qayed-paired.json` — un horodatage, rien
d'autre, aucun secret — **au moment précis** où WhatsApp confirme la session
(`ready`), et nulle part avant. C'est ce marqueur, et non l'existence des
fichiers, qui fait foi. Il voyage dans l'archive : une session restaurée est
appairée par construction.

Un profil sans marqueur n'est jamais déposé au coffre, et jamais préféré à une
archive saine.

## Variables

Côté worker (`whatsapp-service`) :

| Variable | Défaut | Rôle |
|---|---|---|
| `WHATSAPP_SESSION_VAULT_ENABLED` | `1` | `0` désactive le coffre (la session redevient un exemplaire unique) |
| `WHATSAPP_SESSION_SNAPSHOT_DELAY_MS` | `180000` | Attente après « ready » avant le premier dépôt |
| `WHATSAPP_SESSION_SNAPSHOT_INTERVAL_MS` | `21600000` | Cadence des dépôts (6 h) |
| `WHATSAPP_WATCHDOG_MS` | `420000` | Blocage au démarrage → redémarrage (session conservée) |
| `WHATSAPP_ALLOW_SESSION_WIPE` | *(vide)* | ⚠️ `1` autorise l'effacement de la session. Ré-appairage volontaire uniquement |

Côté backend : `WHATSAPP_SESSION_VAULT_ENABLED`, `WHATSAPP_SESSION_VAULT_MIN_BYTES` (plancher de refus), `WHATSAPP_SESSION_VAULT_MAX_BYTES`. Le coffre réutilise `BACKUP_S3_*` et `BACKUP_ENCRYPTION_KEY` — **aucune variable supplémentaire n'est requise** si les sauvegardes fonctionnent.

## Vérifier après un déploiement

```
GET https://<worker>/session-vault?token=<WHATSAPP_QR_TOKEN>
```

```json
{
  "ready": true,
  "session": "ready",
  "local":  { "usable": true, "paired": true, "stores": ["IndexedDB", "Local Storage"], "megabytes": 312.4 },
  "vault":  { "exists": true, "bytes": 18234112, "stored_at": "2026-08-08T…" }
}
```

- `local.usable: true` — le volume a bien conservé la session.
- `vault.exists: true` — une copie durable existe.
- `ready: true` et aucun QR sur `/qr` — la session a survécu.

Dans les journaux du worker, la ligne à voir est :

```
[wa-session] session locale présente (IndexedDB, Local Storage, …) — le coffre n'est pas sollicité.
```

C'est le chemin nominal : le coffre est un filet, pas un passage obligé.

## Tests

`whatsapp-service/session-store.test.js` (`npm test`, `node --test`, sans dépendance) couvre :

- une session existante n'est ni effacée ni remplacée au démarrage ;
- une instance recréée de zéro retrouve la session, octet pour octet ;
- un worker sans volume ne peut pas écraser le coffre ;
- un blocage au démarrage n'efface plus rien ;
- un coffre injoignable ne détruit rien ;
- aucun fragment de session dans les journaux.

`tests/Feature/WhatsappSessionVaultTest.php` couvre le côté backend : authentification par secret partagé, aller-retour à l'octet près, chiffrement au repos, refus des archives trop petites ou corrompues sans toucher à l'existant, absence de secret dans les journaux.

Aucune session WhatsApp réelle n'apparaît dans les tests : les archives sont factices.

## Ce qui reste

- **Le pin de version WhatsApp Web expire le 10 septembre 2026.** Le coffre protège la session, pas l'échéance de la bibliothèque. Voir [canal-transmission.md](canal-transmission.md).
- **La taille réelle du profil en production n'a pas été mesurée.** Si un dépôt est refusé pour dépassement, relever `WHATSAPP_SESSION_VAULT_MAX_BYTES` **et** `post_max_size` (Dockerfile, actuellement 70M).
