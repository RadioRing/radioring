#!/bin/sh
set -e

case "${APP_MODE:-all}" in
    all|web)
        wget -qO /dev/null http://localhost:8080/up || exit 1
        ;;
    queue)
        # Both workers have to be up. Without the default one no rundowns are
        # generated, without the media one no station container starts. The
        # patterns match the invocations in entrypoint.sh: the default worker is
        # the only one started without a connection name.
        pgrep -f "artisan queue:work --sleep" >/dev/null || exit 1
        pgrep -f "artisan queue:work media" >/dev/null || exit 1
        ;;
    scheduler)
        pgrep -f "artisan schedule:work" >/dev/null || exit 1
        ;;
    *)
        exit 1
        ;;
esac
