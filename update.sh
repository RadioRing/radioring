#!/bin/sh
# RadioRing updater. Liegt neben der docker-compose.yml der Installation.
#
#   ./update.sh           Images ziehen und neu starten
#   ./update.sh --check   nur pruefen, ob es neuere Images gibt

set -eu

cd "$(dirname "$0")"

[ -f docker-compose.yml ] || { echo "Keine docker-compose.yml in $(pwd)." >&2; exit 1; }

if [ "${1:-}" = "--check" ]; then
    echo "Vergleiche lokale Images mit der Registry ..."
    docker compose pull --dry-run 2>/dev/null || docker compose pull
    exit 0
fi

echo "Vor einem Update ein Backup der Datenbank anlegen. Weiter mit Enter, Abbruch mit Ctrl-C."
if [ -t 0 ]; then
    read -r _ignored || true
fi

docker compose pull
docker compose up -d

# Migrationen laufen im Entrypoint des Containers. Nur wenn die App aufgeteilt
# betrieben wird (APP_MODE != all), muessen sie hier explizit angestossen werden.
if [ "$(grep -E '^APP_MODE=' .env 2>/dev/null | cut -d= -f2)" != "all" ]; then
    docker compose exec -T app php artisan migrate --force
fi

docker image prune -f >/dev/null 2>&1 || true

echo "Update abgeschlossen."
