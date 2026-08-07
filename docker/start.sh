#!/bin/sh
set -e

# ─────────────────────────────────────────────────────────────────────────────
# Démarrage production Qayed.
#
# Remplace l'ancien CMD en une ligne, qui commençait par
# `php artisan key:generate --force`. Cette commande forgeait silencieusement
# une NOUVELLE clé applicative à chaque démarrage si APP_KEY manquait dans
# l'environnement — rendant définitivement illisible tout ce qui avait été
# chiffré avec l'ancienne (secrets 2FA, et scans d'identité une fois leur
# chiffrement activé). On échoue désormais bruyamment au lieu de masquer la
# mauvaise configuration.
# ─────────────────────────────────────────────────────────────────────────────

if [ -z "${APP_KEY}" ]; then
  echo "FATAL: APP_KEY absent de l'environnement." >&2
  echo "       Générez-la une fois (php artisan key:generate --show) puis" >&2
  echo "       définissez-la comme variable d'environnement du service." >&2
  echo "       Ne jamais laisser le conteneur en générer une au démarrage :" >&2
  echo "       toute donnée chiffrée avec la clé précédente serait perdue." >&2
  exit 1
fi

echo "→ Migrations"
php artisan migrate --force

echo "→ Seeders référentiels"
for seeder in \
  CountrySeeder \
  DocumentTypeSeeder \
  RolesAndPermissionsSeeder \
  SubscriptionPlanSeeder \
  PlatformAdminSeeder \
  AiPricingSeeder \
  HomePageSeeder \
  LegalPagesSeeder
do
  php artisan db:seed --class="${seeder}" --force || echo "  ! ${seeder} a échoué (ignoré)"
done

echo "→ Mise en cache de la configuration"
php artisan config:cache
php artisan route:cache

# ─────────────────────────────────────────────────────────────────────────────
# Planificateur.
#
# Remplace une boucle « schedule:run ; sleep 60 » qui posait deux problèmes
# sérieux, tous deux constatés lors de la vérification des sauvegardes :
#
#  1. DÉRIVE — sleep 60 PLUS la durée d'exécution donne une période > 60 s. Le
#     planificateur ne déclenche une tâche horaire que si un passage tombe dans
#     la bonne minute : la dérive faisait donc silencieusement sauter des
#     créneaux. `schedule:work` est la primitive du framework et se cale sur la
#     seconde 0 de chaque minute, sans dérive cumulative.
#
#  2. ERREURS JETÉES — « >/dev/null 2>&1 || true » supprimait sortie ET code de
#     retour. Un échec de sauvegarde était donc totalement invisible. La sortie
#     part désormais sur stderr du conteneur, où Railway la capture.
# ─────────────────────────────────────────────────────────────────────────────
echo "→ Planificateur (schedule:work, arrière-plan)"
php artisan schedule:work >&2 &
SCHEDULER_PID=$!

# Si le planificateur meurt, plus rien ne tourne : ni sauvegarde, ni
# facturation, ni purge — pendant que le serveur web continue de répondre
# normalement. On le relance et on le signale bruyamment.
(
  while true; do
    sleep 60
    if ! kill -0 "$SCHEDULER_PID" 2>/dev/null; then
      echo "ALERTE: le planificateur s'est arrêté — redémarrage" >&2
      php artisan schedule:work >&2 &
      SCHEDULER_PID=$!
    fi
  done
) &

# FrankenPHP sert /app/public. SERVER_NAME sous la forme ":port" = HTTP simple,
# sans HTTPS automatique : c'est Railway qui termine TLS en amont.
export SERVER_NAME=":${PORT:-8000}"

echo "→ FrankenPHP sur ${SERVER_NAME}"
exec frankenphp run --config /etc/caddy/Caddyfile
