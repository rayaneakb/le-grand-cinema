#!/bin/sh
set -eu

log() {
    printf '%s [demarrage] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$1"
}

run_step() {
    step="$1"
    shift

    log "$step — début"
    if "$@"; then
        log "$step — terminé"
    else
        code=$?
        log "$step — échec (code $code)"
        exit "$code"
    fi
}

log "Démarrage du conteneur"

: "${DATABASE_URL:?La connexion PostgreSQL doit être configurée.}"
: "${APP_SECRET:?Le secret Symfony doit être configuré.}"
export PORT="${PORT:-10000}"

cd /var/www/app/backend

run_step "Connexion PostgreSQL et migrations" php bin/console doctrine:migrations:migrate --no-interaction

if [ "${LOAD_DEMO_DATA:-0}" = "1" ]; then
    run_step "Chargement des données de démonstration" php bin/console app:load-demo --no-interaction
fi

run_step "Nettoyage du cache Symfony" php bin/console cache:clear --no-warmup
run_step "Préparation du cache Symfony" php bin/console cache:warmup
run_step "Permissions du cache" chown -R www-data:www-data var

log "Lancement d’Apache sur le port $PORT"
exec apache2-foreground
