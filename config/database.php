<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Configuration base de données
|--------------------------------------------------------------------------
|
| Copie publiée de la configuration du framework (les connexions 'pgsql' et
| 'sqlite' ci-dessous sont inchangées par rapport au défaut Laravel), pour une
| seule raison : la connexion 'prospection'.
|
| Le mini-CRM de prospection commerciale (maisons d'hôtes ciblées, statut du
| pipeline, journal des relances) ne doit JAMAIS pouvoir se retrouver dans la
| même requête qu'une fiche voyageur — ce sont deux registres différents, l'un
| interne et sans enjeu réglementaire, l'autre couvert par les obligations
| fiche de police. Une connexion Eloquent séparée rend ce mélange impossible
| au niveau du code : aucun modèle Prospection\* ne peut, même par erreur,
| lire ou écrire une table de production.
|
| L'isolation se fait par SCHÉMA Postgres ('prospection', via search_path),
| pas par base physique séparée : le même cluster Railway suffit et aucune
| ressource supplémentaire n'est nécessaire pour démarrer. Poser
| PROSPECTION_DB_DATABASE (et éventuellement PROSPECTION_DB_HOST/PORT/
| USERNAME/PASSWORD) permet de basculer vers une base entièrement séparée
| plus tard sans changer une ligne de code applicatif — voir README.md.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        /*
         | Registre interne de prospection — voir le commentaire d'en-tête.
         |
         | Par défaut, MÊME base physique que 'pgsql' (même hôte, même nom de
         | base, mêmes identifiants) : aucune ressource Postgres
         | supplémentaire à provisionner sur Railway pour démarrer. Ce qui
         | isole réellement les données, c'est `search_path` — un schéma
         | Postgres ("prospection") distinct de "public", où vivent les
         | tables de production. La migration 0001
         | (create_prospection_schema) crée ce schéma s'il n'existe pas ; il
         | ne demande que le privilège CREATE sur la base, dont le
         | propriétaire dispose toujours.
         |
         | Poser PROSPECTION_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD (par
         | exemple avec les identifiants d'un second plugin Postgres Railway)
         | fait basculer vers une base entièrement séparée, sans changer une
         | ligne de code applicatif.
         |
         | `env('X') ?: fallback` et non `env('X', fallback)` : ces variables
         | sont documentées comme "vide = repli" dans .env.example (même
         | idiome que WEBAUTHN_RP_ID/WebauthnOrigins), et le second argument
         | de env() n'agit QUE quand la clé est totalement ABSENTE — une
         | valeur explicitement vide (le cas courant ici) le court-circuite.
         */
        'prospection' => [
            'driver' => 'pgsql',
            'host' => env('PROSPECTION_DB_HOST') ?: env('DB_HOST', '127.0.0.1'),
            'port' => env('PROSPECTION_DB_PORT') ?: env('DB_PORT', '5432'),
            'database' => env('PROSPECTION_DB_DATABASE') ?: env('DB_DATABASE', 'laravel'),
            'username' => env('PROSPECTION_DB_USERNAME') ?: env('DB_USERNAME', 'root'),
            'password' => env('PROSPECTION_DB_PASSWORD') ?: env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('PROSPECTION_DB_SCHEMA') ?: 'prospection',
            'sslmode' => 'prefer',
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
