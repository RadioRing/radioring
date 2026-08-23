#!/bin/sh
# RadioRing updater. Sits next to the docker-compose.yml of an installation.
#
#   ./update.sh                  Refresh the template, pull images, restart
#   ./update.sh --check          Only check whether newer images exist
#   ./update.sh --keep-compose   Leave the local docker-compose.yml untouched

set -eu

cd "$(dirname "$0")"

[ -f docker-compose.yml ] || { echo "No docker-compose.yml in $(pwd)." >&2; exit 1; }

KEEP_COMPOSE=0
CHECK_ONLY=0
for _arg in "$@"; do
    case "$_arg" in
        --check) CHECK_ONLY=1 ;;
        --keep-compose) KEEP_COMPOSE=1 ;;
        *) echo "Unknown option: $_arg" >&2; exit 1 ;;
    esac
done

# Where the template comes from. install.sh records RR_REPO_RAW in the .env so
# that a fork pulls its own template rather than the original one.
REPO_RAW=$(grep -E '^RR_REPO_RAW=' .env 2>/dev/null | cut -d= -f2- || true)
[ -n "$REPO_RAW" ] || REPO_RAW="https://raw.githubusercontent.com/radioring/radioring/main"

if [ "$CHECK_ONLY" = "1" ]; then
    echo "Comparing local images against the registry ..."
    docker compose pull --dry-run 2>/dev/null || docker compose pull
    exit 0
fi

echo "Back up the database before updating. Enter to continue, Ctrl-C to abort."
if [ -t 0 ]; then
    read -r _ignored || true
fi

# Refresh the template before pulling images.
#
# Without this step an installation stays frozen at the state of the day it was
# installed: 'docker compose pull' only fetches newer images for the tags that
# the local file names. A tag change in the template (traefik:v3.1 to v3.6, say,
# because newer Docker engines reject the old API version) would reach nobody
# who has already installed. The same goes for new services and changed
# healthchecks.
#
# This is safe because install.sh stores the template unchanged: everything
# installation specific lives in the .env, none of it in this file.
if [ "$KEEP_COMPOSE" = "1" ]; then
    echo "docker-compose.yml left untouched (--keep-compose)."
elif curl -fsSL "$REPO_RAW/docker/templates/docker-compose.yml" -o docker-compose.yml.new 2>/dev/null; then
    if cmp -s docker-compose.yml docker-compose.yml.new; then
        rm -f docker-compose.yml.new
    else
        cp docker-compose.yml docker-compose.yml.bak
        mv docker-compose.yml.new docker-compose.yml
        echo "docker-compose.yml updated. Previous version: docker-compose.yml.bak"
        echo "Local edits to that file are overwritten; 'diff docker-compose.yml.bak docker-compose.yml' shows them."
    fi
else
    rm -f docker-compose.yml.new
    echo "Warning: could not download docker-compose.yml from $REPO_RAW, keeping the local one." >&2
fi

docker compose pull
docker compose up -d

# Migrations run in the container entrypoint. They only have to be triggered
# here when the app is split across containers (APP_MODE != all).
if [ "$(grep -E '^APP_MODE=' .env 2>/dev/null | cut -d= -f2)" != "all" ]; then
    docker compose exec -T app php artisan migrate --force
fi

docker image prune -f >/dev/null 2>&1 || true

echo "Update finished."
