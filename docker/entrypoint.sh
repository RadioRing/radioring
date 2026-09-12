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
# all       → FrankenPHP (Vordergrund) + Worker + Scheduler (nebenläufig)
# web       → nur FrankenPHP
# queue     → nur die Worker
# scheduler → nur Scheduler
#
# Kein Supervisor: FrankenPHP (Go) läuft als Hauptprozess. Worker und Scheduler
# laufen in Auto-Restart-Schleifen nebenher (queue:work beendet sich nach
# --max-time und wird neu gestartet). Stirbt FrankenPHP, endet der Container.
#
# WICHTIG: '|| true' hinter queue:work/schedule:work. Dieses Script läuft mit
# 'set -e', und ein nicht-null Exit (SIGTERM=143, OOM=137, Fehler beim Shutdown)
# im Schleifen-BODY würde sonst die Subshell killen – die 'while true'-Schleife
# käme nie zur Neustart-Zeile, der Worker wäre dauerhaft tot (FrankenPHP läuft
# weiter, also greift auch die Docker-Restart-Policy nicht) und Jobs stauen sich.

# Zwei Worker, weil die Jobs zwei sehr verschiedene Laufzeiten haben:
#
#   default  Rundown-Generierung, HTTP-Prefetch, Zeitplan-Dateien. Sekunden,
#            aber programmkritisch: was hier wartet, fehlt on air.
#   media    Lautheitsmessung (ffmpeg), Backups, Container-Start inkl.
#            Image-Pull. Minuten bis zu einer Stunde.
#
# In einer gemeinsamen Queue blockiert ein zehnminütiger Image-Pull alles
# dahinter. --timeout beim media-Worker gilt für Jobs ohne eigenes $timeout und
# liegt unter dem retry_after der media-Verbindung (config/queue.php), sonst
# würde derselbe Job ein zweites Mal ausgeliefert, während der erste noch läuft.
start_queue_workers() {
    ( while true; do php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true; echo "[entrypoint] queue worker (default) beendet, Neustart in 2s"; sleep 2; done ) &
    ( while true; do php artisan queue:work media --sleep=5 --tries=3 --timeout=1800 --max-time=3600 || true; echo "[entrypoint] queue worker (media) beendet, Neustart in 2s"; sleep 2; done ) &
}

case "${APP_MODE:-all}" in
    all)
        start_queue_workers
        ( while true; do php artisan schedule:work || true; echo "[entrypoint] scheduler beendet, Neustart in 2s"; sleep 2; done ) &
        exec frankenphp php-server --listen :8080 --root public/
        ;;
    web)
        exec frankenphp php-server --listen :8080 --root public/
        ;;
    queue)
        # Kein exec möglich, es sind zwei Prozesse. Das Trap gibt 'docker stop'
        # an beide weiter, sonst liefe die Stop-Frist jedes Mal voll aus.
        trap 'kill 0' TERM INT
        start_queue_workers
        wait
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown APP_MODE: ${APP_MODE}" >&2
        exit 1
        ;;
esac
