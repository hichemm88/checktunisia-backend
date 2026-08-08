# Déploiement — backend Qayed

## Architecture des services

L'image Docker est unique ; c'est la commande de démarrage qui distingue les rôles.

| Service Railway | Commande | Rôle |
|---|---|---|
| `backend` (web) | `/usr/local/bin/qayed-start` (défaut de l'image) | FrankenPHP + migrations + planificateur |
| `worker` (à créer) | `/usr/local/bin/qayed-worker` | Traitement de la file uniquement |

## Variables obligatoires

**`APP_KEY` est requise et le conteneur refuse de démarrer sans elle.** C'est délibéré : l'ancienne commande de démarrage lançait `php artisan key:generate --force`, ce qui forgeait silencieusement une nouvelle clé quand la variable manquait — rendant définitivement illisible tout ce qui avait été chiffré avec la précédente (secrets 2FA aujourd'hui, scans d'identité demain).

**Le service worker doit porter exactement la même `APP_KEY` que le service web.** Deux clés différentes produiraient des erreurs de déchiffrement asymétriques, très difficiles à diagnostiquer.

## Mettre en place le worker dédié

Aujourd'hui, le service web draine la file une fois par minute via le planificateur. Cela fonctionne, mais impose jusqu'à ~60 secondes de latence — y compris sur les emails d'alerte de watchlist, qui sont sensibles au temps — et vole du CPU aux requêtes HTTP.

Marche à suivre :

1. Créer un second service Railway à partir du **même dépôt et de la même image**.
2. Commande de démarrage : `/usr/local/bin/qayed-worker`.
3. Copier toutes les variables d'environnement du service web, `APP_KEY` comprise.
4. Une fois le worker vert, poser `QUEUE_DRAIN_VIA_SCHEDULER=false` **sur le service web uniquement**.

L'étape 4 n'est pas une question de correction mais de gaspillage : les deux peuvent coexister sans corruption, le retrait d'un job en file étant atomique. Aucun job ne sera traité deux fois.

Pour revenir en arrière, repasser `QUEUE_DRAIN_VIA_SCHEDULER` à `true` (ou retirer la variable) et arrêter le service worker. Le planificateur reprend le drainage à la minute suivante.

## Ce qui reste sur le service web

Le planificateur ne bouge pas : il porte la facturation, les relances, la purge des images WhatsApp et la surveillance de santé du relais. Seul le drainage de la file en sort.

**La journalisation d'audit reste synchrone et ne doit jamais passer en asynchrone.** C'est la trace légale du produit : une écriture d'audit qui peut être perdue dans une file n'est plus une piste d'audit. Cette contrainte prime sur toute considération de latence.

## Surveiller

`GET /api/v1/admin/health` (réservé aux administrateurs plateforme) expose :

- `queue.pending` — profondeur de la file. Si elle monte sans redescendre, le worker est mort.
- `queue.failed_total` — jobs définitivement abandonnés.
- `scheduler.stale` — `true` si le planificateur n'a pas battu depuis plus de 5 minutes. Un planificateur mort est invisible autrement : le serveur web répond normalement pendant que plus rien n'avance.
- `whatsapp.pending` / `failed` — état de l'outbox du canal de transmission légal.

`GET /api/v1/admin/health/failed-jobs` liste les 50 derniers abandons (classe, erreur, date — jamais la charge utile, qui contient des adresses email et des identifiants d'établissement).

`POST /api/v1/admin/health/failed-jobs/{uuid}/retry` rejoue un job.

`/up` reste l'endpoint de vivacité pour le healthcheck Railway — il ne dit que « le processus répond ».

## Politique de reprise

Chaque job porte sa propre politique plutôt que de dépendre des drapeaux du worker : `ExportPoliceFichesJob` déclare `$tries = 3`, `$backoff = [10, 60, 300]` et `$timeout = 300`. Un worker lancé sans `--tries` conserve donc un comportement correct.

Après épuisement des tentatives, `failed()` trace l'abandon avec de quoi rejouer manuellement (établissement, plage de dates) — sans l'adresse email du destinataire, qui est une donnée personnelle.

## Rappel : le seeding au démarrage

Le script de démarrage rejoue les seeders référentiels à chaque boot, chacun tolérant l'échec. C'est pratique mais fragile : un seeder cassé rallonge chaque déploiement. À terme, le sortir du chemin de démarrage pour en faire une commande de version.
