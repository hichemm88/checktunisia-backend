# Intégration continue — backend

## Ce qui tourne, et quand

Le pipeline est défini dans `.github/workflows/ci.yml`. Il se déclenche sur **chaque pull request** et sur **chaque push vers `main`**. Une exécution en cours est annulée si un nouveau push arrive sur la même branche.

| Job | Contenu | Bloquant |
|---|---|---|
| `tests` | Suite PHPUnit complète (Unit + Feature) contre un PostgreSQL 16 réel | Oui |
| `quality` | `composer validate --strict` + `composer audit --no-dev` | Oui |

Le frontend a son propre pipeline dans le dépôt `checktunisia` : lint, typecheck, tests, build, et `npm audit --omit=dev --audit-level=high`.

## Pourquoi un vrai PostgreSQL et pas SQLite

La suite utilise `RefreshDatabase` contre PostgreSQL parce que le code en dépend réellement : index uniques partiels, `INSERT … ON CONFLICT`, `ILIKE`, `DISTINCT ON`, colonnes JSONB. Une exécution sur SQLite passerait à côté de la moitié des invariants et donnerait une fausse assurance.

## Le détail qui surprend : l'alias `postgres`

`phpunit.xml` force `DB_HOST=postgres` avec `force="true"`, et ce n'est pas un oubli — le commentaire du fichier explique que sans ce forçage, les tests s'exécutaient sur la base de développement et la vidaient à chaque passage.

Dans GitHub Actions, le job tourne directement sur le runner : le service PostgreSQL est donc joignable sur `127.0.0.1`, pas sur le nom `postgres`. D'où cette étape :

```yaml
- run: echo "127.0.0.1 postgres" | sudo tee -a /etc/hosts
```

C'est plus simple et plus sûr que de modifier `phpunit.xml`, dont le forçage protège la base de développement.

## Exécuter la suite en local

Aucun PHP n'est installé sur la machine de développement Windows : tout passe par Docker.

```bash
docker compose -f docker-compose.test.yml up -d
```

Puis, une seule fois par session, poser l'alias réseau (même raison que ci-dessus) :

```bash
docker network disconnect backend_default backend-db_test-1 && docker network connect --alias postgres backend_default backend-db_test-1
```

Et lancer les tests :

```bash
docker compose -f docker-compose.test.yml exec -T app php vendor/bin/phpunit
```

Un fichier seul :

```bash
docker compose -f docker-compose.test.yml exec -T app php vendor/bin/phpunit tests/Feature/AuthSecurityTest.php
```

## Vulnérabilités des dépendances

`composer audit --no-dev` est **bloquant**. Ce produit manipule des données d'identité de voyageurs : une CVE connue dans une dépendance livrée n'a pas à atteindre la production.

Remis à zéro le 2026-08-07 — 18 avis affectant `guzzlehttp/guzzle`, `dompdf/dompdf` et `league/commonmark` corrigés par montées de version mineures, sans changement de comportement (suite complète repassée au vert, y compris le test d'export PDF qui vérifie les octets `%PDF` réels).

Quand le job échoue, la marche à suivre est :

```bash
composer update <paquet> --with-dependencies
```

puis relancer la suite complète avant de committer le `composer.lock`.

## Style de code : décision assumée

**Il n'y a volontairement pas de contrôle de style bloquant.**

La base a une convention maison cohérente — alignement des `=>` dans les tableaux, `!$x` sans espace, fins de ligne CRLF — qui diverge du preset Laravel sur environ 230 fichiers sur 276. L'imposer exigerait un reformatage global qui détruirait l'historique `git blame` et entrerait en conflit avec tout travail en cours.

C'est une décision esthétique qui appartient au propriétaire du code, pas au pipeline. `pint.json` est fourni, préconfiguré pour ne pas combattre la convention maison, pour qui veut le lancer manuellement :

```bash
docker compose -f docker-compose.test.yml exec -T app php vendor/bin/pint --test
```

Si la décision est prise un jour de normaliser, la bonne méthode est un commit de reformatage isolé, ajouté à `.git-blame-ignore-revs`, à un moment où aucune branche n'est en vol.

## Faire évoluer le pipeline

Deux règles à respecter :

1. **Ne jamais rendre un job non bloquant.** Un job qui peut échouer sans conséquence n'est pas lu, et il finit rouge en permanence. Soit il bloque, soit il n'existe pas.
2. **Ne jamais désactiver un test pour faire passer le pipeline.** Le test rouge est l'information ; le supprimer supprime l'information, pas le problème.
