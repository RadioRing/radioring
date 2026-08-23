#!/bin/sh
set -e

cd /app

# ── Storage-Verzeichnisse sicherstellen (Volume-Mount kann leer sein) ───────
mkdir -p \
    storage/app/public \
    storage/app/stations \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs
chmod -R 775 storage bootstrap/cache || true

# ── Bootstrap (einmal pro Container-Start) ──────────────────────────────────
php artisan migrate --force --no-interaction
php artisan storage:link 2>/dev/null || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ── Prozess(e) nach APP_MODE starten ────────────────────────────────────────
# all       → FrankenPHP (Vordergrund) + queue + scheduler (nebenläufig)
# web       → nur FrankenPHP
# queue     → nur Queue-Worker
# scheduler → nur Scheduler
#
# Kein Supervisor: FrankenPHP (Go) läuft als Hauptprozess. queue/scheduler laufen
# in Auto-Restart-Schleifen nebenher (queue:work beendet sich nach --max-time und
# wird neu gestartet). Stirbt FrankenPHP, endet der Container.
#
# WICHTIG: '|| true' hinter queue:work/schedule:work. Dieses Script läuft mit
# 'set -e', und ein nicht-null Exit (SIGTERM=143, OOM=137, Fehler beim Shutdown)
# im Schleifen-BODY würde sonst die Subshell killen – die 'while true'-Schleife
# käme nie zur Neustart-Zeile, der Worker wäre dauerhaft tot (FrankenPHP läuft
# weiter, also greift auch die Docker-Restart-Policy nicht) und Jobs stauen sich.
case "${APP_MODE:-all}" in
    all)
        ( while true; do php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true; echo "[entrypoint] queue worker beendet, Neustart in 2s"; sleep 2; done ) &
        ( while true; do php artisan schedule:work || true; echo "[entrypoint] scheduler beendet, Neustart in 2s"; sleep 2; done ) &
        exec frankenphp php-server --listen :8080 --root public/
        ;;
    web)
        exec frankenphp php-server --listen :8080 --root public/
        ;;
    queue)
        exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown APP_MODE: ${APP_MODE}" >&2
        exit 1
        ;;
esac
