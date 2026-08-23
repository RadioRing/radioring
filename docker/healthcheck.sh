#!/bin/sh
set -e

case "${APP_MODE:-all}" in
    all|web)
        wget -qO /dev/null http://localhost:8080/up || exit 1
        ;;
    queue)
        pgrep -f "artisan queue:work" >/dev/null || exit 1
        ;;
    scheduler)
        pgrep -f "artisan schedule:work" >/dev/null || exit 1
        ;;
    *)
        exit 1
        ;;
esac
