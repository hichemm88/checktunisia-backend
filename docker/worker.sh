#!/bin/sh
set -e

# ─────────────────────────────────────────────────────────────────────────────
# Worker de file dédié (INF-03).
#
# À lancer comme service Railway SÉPARÉ, à partir de la même image que le web :
#   Start command : /usr/local/bin/qayed-worker
#
# Pourquoi séparer : le conteneur web draine aujourd'hui la file une fois par
# minute via le planificateur. Conséquences : jusqu'à ~60 s de latence sur les
# emails d'alerte de watchlist — qui sont sensibles au temps — et une
# concurrence CPU avec les requêtes HTTP.
#
# IMPORTANT — quand ce service tourne, poser QUEUE_DRAIN_VIA_SCHEDULER=false
# sur le service WEB pour qu'il cesse de drainer. Les deux peuvent coexister
# sans corruption (le retrait d'un job est atomique), mais c'est du CPU gaspillé.
# ─────────────────────────────────────────────────────────────────────────────

if [ -z "${APP_KEY}" ]; then
  echo "FATAL: APP_KEY absent de l'environnement." >&2
  echo "       Le worker DOIT porter la MÊME clé que le service web :" >&2
  echo "       les données chiffrées seraient sinon illisibles de part et d'autre." >&2
  exit 1
fi

echo "→ Worker de file (connexion : ${QUEUE_CONNECTION:-sync})"

# --tries et --backoff ne sont qu'un filet : chaque job porte sa propre
# politique (voir ExportPoliceFichesJob::$tries / $backoff), pour rester
# correct quelle que soit la façon dont le worker est lancé.
#
# --max-time=3600 : le processus se recycle toutes les heures. Un worker PHP
# de longue durée accumule de la mémoire et garde en cache du code périmé
# après un déploiement ; Railway le relance automatiquement.
exec php artisan queue:work \
  --tries=3 \
  --backoff=10 \
  --max-time=3600 \
  --max-jobs=1000 \
  --sleep=3 \
  --verbose
