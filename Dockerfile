# FrankenPHP remplace `php artisan serve`, qui est le serveur de développement
# de PHP et ne traite qu'UNE requête à la fois : en production, une seule
# requête lente (dashboard, export CSV, envoi SMTP synchrone) bloquait tous les
# autres utilisateurs de la plateforme.
FROM dunglas/frankenphp:1-php8.3

# Extensions PHP. install-php-extensions gère les dépendances système et
# configure GD avec jpeg/webp/freetype (médias CMS, intervention/image).
RUN install-php-extensions \
    pdo_pgsql pgsql \
    zip bcmath mbstring \
    exif pcntl intl gd redis \
    opcache

# pg_dump — requis par « artisan qayed:db-backup ».
#
# PG_MAJOR DOIT correspondre à la version majeure du PostgreSQL de production.
# Ce n'est pas du zèle : un pg_dump plus récent que le serveur produit une
# archive qui paraît saine mais n'est PAS restaurable dans un serveur de la
# version d'origine (depuis PostgreSQL 17, pg_dump émet « transaction_timeout »,
# inconnu des serveurs antérieurs). La commande de sauvegarde refuse d'ailleurs
# de tourner si les deux versions diffèrent.
#
# Dépôt PGDG plutôt que celui de Debian, qui n'offre qu'une seule version.
# 18 = version confirmée du PostgreSQL de production Railway (2026-08-07).
# Railway construit à partir de ce Dockerfile : le défaut suffit, aucune
# variable de build à déclarer côté Railway.
ARG PG_MAJOR=18
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates gnupg \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo $VERSION_CODENAME)-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends "postgresql-client-${PG_MAJOR}" \
    && rm -rf /var/lib/apt/lists/*

# Limites d'upload : le défaut PHP (upload_max_filesize=2M) rejetait les photos
# de documents (3-12 Mo) AVANT la validation Laravel (qui accepte 10 Mo) → la
# fiche WhatsApp partait sans photo, sans erreur visible. Le frontend réduit
# désormais l'image avant envoi, mais l'app mobile et tout autre client
# passent par ici.
#
# 64M / 70M et non 20M / 25M : le worker WhatsApp dépose ici son archive de
# session (profil Chromium appairé, quelques dizaines de Mo une fois les caches
# exclus). Un dépassement de post_max_size vide $_POST SANS erreur PHP — la
# sauvegarde de la session échouerait en silence, ce qui est exactement le
# genre de panne qu'on ne découvre que le jour où on en a besoin.
RUN { echo "upload_max_filesize=64M"; echo "post_max_size=70M"; } > /usr/local/etc/php/conf.d/uploads.ini

# OPcache : sans lui, chaque requête recompile tout le framework.
# validate_timestamps reste à 1 (revalidation toutes les 2 s) car cette même
# image sert le développement via docker-compose, où le code est monté en
# volume : à 0, une modification de source ne serait jamais rechargée.
RUN { \
      echo "opcache.enable=1"; \
      echo "opcache.memory_consumption=192"; \
      echo "opcache.max_accelerated_files=20000"; \
      echo "opcache.validate_timestamps=1"; \
      echo "opcache.revalidate_freq=2"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# Les manifests d'abord : sans cela, toute modification de source invalidait la
# couche composer et chaque déploiement retéléchargeait l'intégralité des
# dépendances.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Puis les sources.
COPY . .

# .env attendu par les commandes artisan au build ; les vraies valeurs viennent
# de l'environnement au runtime.
RUN cp .env.example .env

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# storage/app manquait : git ne versionne pas les répertoires vides, et rien ne
# le créait ici. Flysystem crée ses répertoires à la volée, ce qui masquait le
# problème — mais la redirection shell du dump de sauvegarde, elle, échouait
# sur un conteneur neuf (« Directory nonexistent »). La commande garantit
# désormais elle-même l'existence du répertoire ; ceci est la ceinture en plus
# des bretelles. `private` est la racine du disque Flysystem « local ».
RUN mkdir -p storage/app/private storage/app/backup-tmp storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/qayed-start
COPY docker/worker.sh /usr/local/bin/qayed-worker
RUN chmod +x /usr/local/bin/qayed-start /usr/local/bin/qayed-worker

EXPOSE 8000

# CMD et non ENTRYPOINT : docker-compose surcharge `command:` en développement
# (serveur artisan + montage volume), ce qu'un ENTRYPOINT transformerait en
# simples arguments passés au script de démarrage.
CMD ["/usr/local/bin/qayed-start"]
