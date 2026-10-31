#!/bin/sh
set -eu

: "${DATABASE_URL:?La connexion PostgreSQL doit être configurée.}"
: "${APP_SECRET:?Le secret Symfony doit être configuré.}"
export PORT="${PORT:-10000}"

cd /var/www/app/backend

php bin/console doctrine:migrations:migrate --no-interaction

if [ "${LOAD_DEMO_DATA:-0}" = "1" ]; then
    php bin/console app:load-demo --no-interaction
fi

php bin/console cache:clear --no-warmup
php bin/console cache:warmup
chown -R www-data:www-data var

exec apache2-foreground
