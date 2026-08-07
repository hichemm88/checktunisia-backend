# Sauvegardes et restauration

## Le contexte, qui change tout

Railway ne fournit **ni sauvegarde native ni PITR** sur le plan actuel (vérifié le 2026-08-07). Ce dispositif n'est donc pas un filet secondaire : **c'est la seule protection du registre des déclarations de voyageurs.** Chaque garde-fou décrit ici est dimensionné en conséquence.

## Architecture

```
PostgreSQL (Railway)
   ↓ pg_dump                      versions majeures IDENTIQUES exigées
   ↓ gzip -9
   ↓ XChaCha20-Poly1305           chiffrement authentifié, en flux (libsodium)
   ↓ envoi
Stockage objet hors Railway       daily/qayed-AAAA-MM-JJ_HHMMSS.sql.gz.enc
   ↓ purge après succès           30 jours, jamais la plus récente
```

Aucun SQL en clair ne quitte le conteneur : le dump est chiffré **avant** l'envoi, et le fichier en clair est supprimé avant tout appel réseau.

## Planification — pourquoi horaire et non quotidienne

La commande tourne **toutes les heures** et décide elle-même si le travail est dû (dernière réussite datant de plus de `BACKUP_INTERVAL_HOURS`, 24 par défaut).

Ce n'est pas un détail. Une planification `dailyAt('03:30')` n'a qu'une seule minute par jour pour se déclencher : si le planificateur manque cette minute — et l'ancienne boucle `sleep 60` dérivait suffisamment pour que cela arrive — c'est une journée entière de registre perdue, sans le moindre signal. Ici, un créneau manqué est simplement rattrapé à l'heure suivante.

Le planificateur lui-même utilise désormais `php artisan schedule:work` (primitive du framework, calée sur la seconde 0 de chaque minute) au lieu d'une boucle artisanale, et sa sortie part sur stderr du conteneur où Railway la capture. Il est surveillé et relancé s'il meurt.

## Chiffrement

**XChaCha20-Poly1305 en mode « secretstream »** (libsodium, dans le cœur de PHP). Choisi pour trois raisons :

- **authentifié** — toute altération d'un octet est détectée au déchiffrement. Sur une archive conservée des mois, la corruption silencieuse est le vrai risque ;
- **en flux** — chiffrement par blocs de 1 Mio, à mémoire constante ; un dump de plusieurs Go ne tiendrait pas en mémoire ;
- **standard** — aucune cryptographie maison.

Le dernier bloc porte un marqueur `FINAL`. Une archive tronquée est donc **détectée**, jamais restaurée à moitié.

### Format

```
"QYDBKP01"        nombre magique + version
<longueur id>     1 octet
<identifiant clé> en CLAIR — jamais la clé
<en-tête sodium>  24 octets
<blocs chiffrés>
```

### Gestion et rotation des clés

La clé ne vit **que** dans l'environnement d'exécution. Elle n'est jamais écrite dans le bucket : qui obtient les sauvegardes sans la clé n'obtient rien d'exploitable.

Chaque fichier embarque l'**identifiant** de la clé qui l'a chiffré. Une rotation ne rend donc pas l'historique illisible :

1. Générer une clé : `php artisan tinker --execute="echo app(\App\Services\Backup\BackupKeyring::class)->generateKey();"`
2. Déplacer la clé courante dans `BACKUP_ENCRYPTION_PREVIOUS_KEYS` au format `k1:<base64>`
3. Poser la nouvelle dans `BACKUP_ENCRYPTION_KEY` et incrémenter `BACKUP_ENCRYPTION_KEY_ID` (`k2`)

Les nouvelles sauvegardes utilisent `k2` ; les anciennes restent déchiffrables avec `k1`.

**Le piège à éviter :** retirer une ancienne clé de `BACKUP_ENCRYPTION_PREVIOUS_KEYS` rend définitivement irrécupérables toutes les sauvegardes qu'elle protège. Ne retirer une clé qu'une fois la dernière archive qui l'utilise sortie de la rétention.

⚠️ **Perdre la clé sans la sauvegarder équivaut à perdre les sauvegardes.** Conservez-la dans un gestionnaire de mots de passe, séparément des identifiants du bucket.

## Compatibilité de versions PostgreSQL

La commande **refuse de tourner** si la version majeure de `pg_dump` diffère de celle du serveur. Les deux sens de l'écart posent problème :

| Écart | Conséquence |
|---|---|
| `pg_dump` plus **ancien** | Refuse de dumper. Échec franc. |
| `pg_dump` plus **récent** | Dumpe sans broncher, mais émet des directives inconnues du serveur d'origine (`transaction_timeout` depuis PostgreSQL 17). **L'archive paraît saine et n'est pas restaurable.** |

Le second cas est le dangereux : on croit avoir une sauvegarde. Il a été découvert en exécutant une vraie restauration, pas en lisant le code.

### Version en vigueur

| | Version |
|---|---|
| PostgreSQL de production (Railway) | **18.4** — confirmé le 2026-08-07 |
| `PG_MAJOR` (argument de build du `Dockerfile`) | **18** |
| `pg_dump` dans l'image de production | **18.4** (PGDG `postgresql-client-18`) |
| Base et client de l'environnement de test | **18** |

Railway construit à partir du `Dockerfile` : le défaut `PG_MAJOR=18` suffit, **aucune variable de build n'est à déclarer côté Railway**.

⚠️ **Si Railway monte un jour PostgreSQL à 19**, la sauvegarde s'arrêtera net avec un message explicite plutôt que de produire des archives inutilisables. Il faudra alors passer `PG_MAJOR` à 19 et redéployer.

📌 **Le `docker-compose.yml` de développement local reste en PostgreSQL 16.** Il porte un volume nommé (`postgres_data`) : le passer en 18 rendrait le répertoire de données illisible et détruirait la base locale. La migration se fait manuellement (dump, suppression du volume, restauration) — sans impact sur la production ni sur les tests.

## Permissions du stockage — deux jeux d'identifiants distincts

L'application de production ne doit **pas** pouvoir lire les sauvegardes : une compromission de l'application donnerait sinon accès à tout l'historique du registre.

### Identifiants ÉCRITURE (dans Railway)

| Permission | Pourquoi |
|---|---|
| `PutObject` | déposer la sauvegarde |
| `ListBucket` | purge de rétention |
| `DeleteObject` | purge de rétention |

**Pas de `GetObject`.** Aucun chemin de code applicatif ne lit le bucket.

### Identifiants RESTAURATION (hors Railway, chez l'opérateur)

| Permission | Pourquoi |
|---|---|
| `GetObject` | télécharger une archive |
| `ListBucket` | retrouver la bonne |

À conserver dans un gestionnaire de mots de passe, jamais dans l'environnement applicatif.

## Restaurer

```bash
# 1. Télécharger — avec les identifiants RESTAURATION
aws s3 cp s3://VOTRE_BUCKET/daily/qayed-AAAA-MM-JJ_HHMMSS.sql.gz.enc . --endpoint-url "$ENDPOINT"

# 2. Déchiffrer (exige BACKUP_ENCRYPTION_KEY, ou la clé d'époque)
php artisan qayed:backup-decrypt qayed-AAAA-MM-JJ_HHMMSS.sql.gz.enc

# 3. Restaurer dans une base VIERGE — jamais par-dessus la production
createdb qayed_restore
gunzip -c qayed-AAAA-MM-JJ_HHMMSS.sql.gz | psql -d qayed_restore -v ON_ERROR_STOP=1
```

### Vérification automatisée

Plutôt que de dérouler ces étapes à la main, la commande dédiée fait tout et nettoie derrière elle :

```bash
php artisan qayed:backup-verify-restore chemin/vers/archive.sql.gz.enc
```

Elle déchiffre, décompresse, crée une base **temporaire**, restaure, contrôle (nombre de tables, extension `pg_trgm`, les trois index trigram, table `migrations`, lisibilité de `guests` / `check_ins` / `travel_documents` / `audit_logs`), puis supprime la base temporaire. Elle ne touche jamais à la production.

**À exécuter au moins une fois par trimestre.** Une sauvegarde jamais restaurée n'est pas une sauvegarde, c'est une hypothèse.

## Surveiller

`GET /api/v1/admin/health` expose une section `backup` :

| Champ | Ce qu'il dit |
|---|---|
| `configured` | faux = aucune sauvegarde ne peut avoir lieu |
| `last_success_at` / `hours_since_success` | fraîcheur réelle |
| `stale` | vrai = il faut agir |
| `last_result` / `last_error` | résultat du dernier passage (message expurgé de tout secret) |
| `last_size_bytes` / `last_duration_seconds` | volumétrie et durée |
| `last_key_id` | quelle clé — jamais la clé |
| `retention` | supprimées, conservées, erreurs éventuelles |

Métadonnées d'exploitation uniquement : aucune donnée voyageur, aucun identifiant, aucune clé.

## En cas d'échec

Un échec ne peut plus disparaître :

1. `Log::error('[backup] ÉCHEC…')` avec contexte structuré ;
2. événement **Sentry** si le DSN est configuré ;
3. état persisté, visible dans `/admin/health` ;
4. code de sortie non nul, et `->onFailure()` sur la tâche planifiée en filet supplémentaire.

Les messages d'erreur sont expurgés du mot de passe de base et des clés avant journalisation.

## Rétention

30 jours par défaut. Trois protections contre l'effacement du dernier filet :

- la sauvegarde **la plus récente n'est jamais supprimée**, quel que soit son âge ;
- une purge ne s'exécute jamais s'il ne reste qu'une seule sauvegarde ;
- un échec de purge est journalisé et visible, **sans** faire passer pour ratée la sauvegarde qui vient de réussir.

La purge n'a lieu qu'**après** un envoi réussi : si les sauvegardes s'arrêtent, l'historique existant est conservé.

## Disque éphémère

Le dump transite par le disque du conteneur. La commande vérifie l'espace libre avant de commencer (`BACKUP_MIN_FREE_DISK_MB`, 512 Mo par défaut) et refuse de démarrer plutôt que de saturer le conteneur et d'emporter le serveur web avec elle.

Les fichiers temporaires sont supprimés en succès **comme en échec** — un dump en clair oublié annulerait tout le bénéfice du chiffrement.

*Faut-il streamer directement vers l'envoi ?* Pas aujourd'hui. À la taille actuelle (dump de quelques dizaines de Mo), le fichier temporaire est simple, vérifiable et permet le contrôle de taille avant envoi. Le streaming vers un envoi multipart chiffré ajouterait de la complexité pour un bénéfice nul. À reconsidérer si le dump dépasse quelques centaines de Mo.
