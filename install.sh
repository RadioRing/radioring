#!/bin/sh
# RadioRing installer.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/radioring/radioring/main/install.sh | sh
#
# POSIX sh on purpose (dash/ash on Debian and Alpine)
set -eu

RR_DIR="${RR_DIR:-/opt/radioring}"
ASSUME_YES="${RR_YES:-0}"
REPO_RAW="${RR_REPO_RAW:-https://raw.githubusercontent.com/radioring/radioring/main}"

for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1 ;;
        --dir=*) RR_DIR="${arg#--dir=}" ;;
        -h|--help)
            cat <<'USAGE'
RadioRing installer

  -y, --yes        Nicht-interaktiv, nimmt alle Vorgaben an
      --dir=PATH   Zielverzeichnis (Vorgabe: /opt/radioring)

Jede Abfrage laesst sich per Umgebungsvariable vorbelegen, z. B.:
  RR_APP_HOST=panel.example.com RR_DB=bundled sh install.sh --yes
USAGE
            exit 0
            ;;
    esac
done

# ---------------------------------------------------------------- Ausgabe ----

if [ -t 1 ]; then
    C_RESET=$(printf '\033[0m'); C_BOLD=$(printf '\033[1m')
    C_RED=$(printf '\033[31m'); C_GREEN=$(printf '\033[32m'); C_YELLOW=$(printf '\033[33m')
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''
fi

say()  { printf '%s\n' "$*"; }
info() { printf '%s==>%s %s\n' "$C_BOLD" "$C_RESET" "$*"; }
ok()   { printf '%s  ok%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn() { printf '%s  !!%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
die()  { printf '%s  xx%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

# --------------------------------------------------------------- Eingaben ----

have_tty() { [ -e /dev/tty ] && [ "$ASSUME_YES" != "1" ]; }

# ask VARNAME "Frage" "Vorgabe"
#
# POSIX sh kennt keine Namensreferenzen, die Zuweisung laeuft daher ueber eval.
# _preset und _answer werden vorher literal gesetzt: sh hat keine lokalen Variablen,
# ein Wert aus einem frueheren Aufruf wuerde sonst durchschlagen.
ask() {
    _name="$1"; _prompt="$2"; _default="$3"
    _preset=''
    _answer=''
    eval "_preset=\${RR_$_name:-}"

    if [ -n "$_preset" ]; then
        eval "$_name=\$_preset"
        return
    fi

    if ! have_tty; then
        eval "$_name=\$_default"
        return
    fi

    if [ -n "$_default" ]; then
        printf '%s [%s]: ' "$_prompt" "$_default" > /dev/tty
    else
        printf '%s: ' "$_prompt" > /dev/tty
    fi

    IFS= read -r _answer < /dev/tty || _answer=''
    [ -z "$_answer" ] && _answer="$_default"
    eval "$_name=\$_answer"
}

# ask_yes VARNAME "Frage" "j|n"
ask_yes() {
    ask "$1" "$2 (j/n)" "$3"
    _v=''
    eval "_v=\$$1"
    case "$_v" in
        j|J|y|Y|ja|yes|true|1) eval "$1=yes" ;;
        *) eval "$1=no" ;;
    esac
}

random_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 24
    else
        head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

app_key() {
    if command -v openssl >/dev/null 2>&1; then
        printf 'base64:%s' "$(openssl rand -base64 32)"
    else
        printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    fi
}

# ---------------------------------------------------------- Vorbedingungen ----

info "Vorbedingungen pruefen"

case "$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ok "Architektur $(uname -m)" ;;
    *) warn "Architektur $(uname -m) ist ungetestet. Es gibt Images fuer amd64 und arm64." ;;
esac

if ! command -v docker >/dev/null 2>&1; then
    warn "Docker ist nicht installiert."
    ask_yes INSTALL_DOCKER "Docker jetzt ueber get.docker.com installieren?" "j"
    if [ "$INSTALL_DOCKER" = "yes" ]; then
        curl -fsSL https://get.docker.com | sh || die "Docker-Installation fehlgeschlagen."
    else
        die "Docker wird benoetigt. Anleitung: https://docs.docker.com/engine/install/"
    fi
fi

docker compose version >/dev/null 2>&1 || die "Docker Compose v2 fehlt (docker compose version)."
docker info >/dev/null 2>&1 || die "Kein Zugriff auf den Docker-Daemon. Als root ausfuehren oder in die Gruppe docker aufnehmen."
ok "Docker einsatzbereit"

# -------------------------------------------------------------- Bestehend ----

MODE_LABEL="Neuinstallation"
if [ -f "$RR_DIR/.env" ]; then
    MODE_LABEL="Reparatur / Aktualisierung"
    info "Vorhandene Installation in $RR_DIR gefunden"
    # Vorhandene Werte werden zu Vorgaben. APP_KEY wird NIE neu erzeugt.
    # shellcheck disable=SC1091
    . "$RR_DIR/.env" 2>/dev/null || true
    cp "$RR_DIR/.env" "$RR_DIR/.env.bak.$(date +%s)"
    ok "Sicherung der bisherigen .env angelegt"
fi

# --------------------------------------------------------------- Abfragen ----

info "$MODE_LABEL - Konfiguration"

ask APP_HOST       "Domain des Panels"                    "${APP_HOST:-panel.example.com}"
ask IMAGE_OWNER    "GitHub-Owner der Images"              "${IMAGE_OWNER:-radioring}"
ask IMAGE_TAG      "Image-Tag"                            "${IMAGE_TAG:-latest}"

ask CONTAINER_DRIVER "Container-Steuerung (docker|portainer)" "${CONTAINER_DRIVER:-docker}"
if [ "$CONTAINER_DRIVER" = "portainer" ]; then
    ask PORTAINER_ENDPOINT    "Portainer-API-Endpoint"     "${PORTAINER_ENDPOINT:-https://portainer.example.com/api}"
    ask PORTAINER_TOKEN       "Portainer-API-Token"        "${PORTAINER_TOKEN:-}"
    ask PORTAINER_ENVIRONMENT "Portainer-Environment-ID"   "${PORTAINER_ENVIRONMENT:-2}"
fi

ask RR_DB "Datenbank: bundled oder external" "${RR_DB:-bundled}"
if [ "$RR_DB" = "external" ]; then
    ask DB_HOST     "MySQL-Host"     "${DB_HOST:-}"
    ask DB_PORT     "MySQL-Port"     "${DB_PORT:-3306}"
    ask DB_DATABASE "Datenbankname"  "${DB_DATABASE:-radioring}"
    ask DB_USERNAME "Benutzer"       "${DB_USERNAME:-radioring}"
    ask DB_PASSWORD "Passwort"       "${DB_PASSWORD:-}"
else
    DB_HOST="mysql"; DB_PORT="3306"
    DB_DATABASE="${DB_DATABASE:-radioring}"
    DB_USERNAME="${DB_USERNAME:-radioring}"
    DB_PASSWORD="${DB_PASSWORD:-$(random_secret)}"
    DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$(random_secret)}"
fi

ask RR_REDIS "Redis: bundled oder external" "${RR_REDIS:-bundled}"
if [ "$RR_REDIS" = "external" ]; then
    ask REDIS_HOST     "Redis-Host"     "${REDIS_HOST:-}"
    ask REDIS_PORT     "Redis-Port"     "${REDIS_PORT:-6379}"
    ask REDIS_PASSWORD "Redis-Passwort" "${REDIS_PASSWORD:-}"
else
    REDIS_HOST="redis"; REDIS_PORT="6379"
    REDIS_PASSWORD="${REDIS_PASSWORD:-$(random_secret)}"
fi

ask RR_PROXY "Reverse Proxy: traefik, external oder none" "${RR_PROXY:-traefik}"
case "$RR_PROXY" in
    traefik)
        ask ACME_EMAIL "E-Mail fuer Let's Encrypt" "${ACME_EMAIL:-admin@$APP_HOST}"
        WEB_NETWORK="radioring-web"; WEB_NETWORK_EXTERNAL="false"; TRAEFIK_ENABLE="true"
        ;;
    external)
        ask WEB_NETWORK "Name des vorhandenen Traefik-Netzes" "${WEB_NETWORK:-web}"
        ask TRAEFIK_CERTRESOLVER "Certresolver deiner Traefik-Instanz" "${TRAEFIK_CERTRESOLVER:-radioring}"
        WEB_NETWORK_EXTERNAL="true"; TRAEFIK_ENABLE="true"; ACME_EMAIL="unused@example.com"
        ;;
    *)
        WEB_NETWORK="radioring-web"; WEB_NETWORK_EXTERNAL="false"; TRAEFIK_ENABLE="false"
        ACME_EMAIL="unused@example.com"
        NO_PROXY_NOTE="yes"
        ;;
esac

# Ohne Reverse Proxy gibt es sonst keinen Weg in die App. Bewusst nur Loopback:
# ein direkt veroeffentlichter Port waere ungeschuetzt und wuerde ausserdem
# trustProxies(at: '*') unterlaufen. Siehe SECURITY.md.
APP_BIND="${APP_BIND:-127.0.0.1}"
APP_PORT="${APP_PORT:-8080}"

ask STREAM_DOMAIN   "Domain fuer den Live-Eingang (leer = kein Live-Ingest)" "${STREAM_DOMAIN:-stream.example.com}"
ask STREAM_PORT_MIN "Erster Port fuer den Live-Eingang" "${STREAM_PORT_MIN:-8001}"
ask STREAM_PORT_MAX "Letzter Port fuer den Live-Eingang" "${STREAM_PORT_MAX:-8099}"

ask RADIORING_MODE "Betriebsmodus (standalone|cloud)" "${RADIORING_MODE:-standalone}"

# ---------------------------------------------------------------- Profile ----

PROFILES="";
[ "$CONTAINER_DRIVER" = "docker" ] && PROFILES="driver-docker"
[ "$RR_DB" = "bundled" ]    && PROFILES="${PROFILES:+$PROFILES,}bundled-db"
[ "$RR_REDIS" = "bundled" ] && PROFILES="${PROFILES:+$PROFILES,}bundled-redis"
[ "$RR_PROXY" = "traefik" ] && PROFILES="${PROFILES:+$PROFILES,}traefik"

APP_KEY="${APP_KEY:-$(app_key)}"

# ---------------------------------------------------------------- Schreiben --

info "Konfiguration schreiben nach $RR_DIR"
mkdir -p "$RR_DIR"
cd "$RR_DIR"

# .env enthaelt DB- und Redis-Passwoerter.
umask 077

cat > .env <<ENVEOF
# Von install.sh erzeugt. Aenderungen hier wirken nach 'docker compose up -d'.
APP_NAME=RadioRing
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$APP_HOST
ASSET_URL=https://$APP_HOST
APP_MODE=all
APP_LOCALE=de
APP_FALLBACK_LOCALE=en

# Startwert. Der wirksame Modus liegt in der Datenbank und ist unter
# /admin/settings umschaltbar, ohne erneutes Deployment.
RADIORING_MODE=$RADIORING_MODE

LOG_CHANNEL=stderr

DB_CONNECTION=mysql
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
${DB_ROOT_PASSWORD:+DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD}

REDIS_HOST=$REDIS_HOST
REDIS_PORT=$REDIS_PORT
REDIS_PASSWORD=$REDIS_PASSWORD
REDIS_DB=0

SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database

# Container-Steuerung. Achtung: wer die Docker-API erreicht, ist effektiv root
# auf dem Host. Siehe SECURITY.md.
CONTAINER_DRIVER=$CONTAINER_DRIVER
DOCKER_HOST=tcp://dockerproxy:2375
DOCKER_API_VERSION=v1.43
DOCKER_STATION_NETWORK=radioring
DOCKER_PULL_TIMEOUT=600
${PORTAINER_ENDPOINT:+PORTAINER_ENDPOINT=$PORTAINER_ENDPOINT}
${PORTAINER_TOKEN:+PORTAINER_TOKEN=$PORTAINER_TOKEN}
${PORTAINER_ENVIRONMENT:+PORTAINER_ENVIRONMENT=$PORTAINER_ENVIRONMENT}

RADIORING_IMAGE=ghcr.io/$IMAGE_OWNER/radioring:$IMAGE_TAG
STATION_IMAGE=ghcr.io/$IMAGE_OWNER/liquidsoap-station:$IMAGE_TAG
STATION_MANAGED_BY=radioring

# Der Station-Container erreicht die App im internen Netz.
LIQUIDSOAP_API_URL=http://app:8080
LIQUIDSOAP_CONTROL_CHANNEL=radioring_station_control
STATION_REDIS_HOST=$REDIS_HOST

STREAM_DOMAIN=$STREAM_DOMAIN
STREAM_PORT_MIN=$STREAM_PORT_MIN
STREAM_PORT_MAX=$STREAM_PORT_MAX

LOUDNESS_NORMALIZATION=true

APP_HOST=$APP_HOST
APP_BIND=$APP_BIND
APP_PORT=$APP_PORT
WEB_NETWORK=$WEB_NETWORK
WEB_NETWORK_EXTERNAL=$WEB_NETWORK_EXTERNAL
TRAEFIK_ENABLE=$TRAEFIK_ENABLE
TRAEFIK_CERTRESOLVER=${TRAEFIK_CERTRESOLVER:-radioring}
ACME_EMAIL=$ACME_EMAIL
COMPOSE_PROFILES=$PROFILES
ENVEOF

# umask allein ist nicht verlaesslich: explizit setzen, die Datei enthaelt die
# Datenbank- und Redis-Passwoerter.
chmod 600 .env 2>/dev/null || warn "Rechte der .env konnten nicht auf 600 gesetzt werden."
ok ".env geschrieben (nur fuer den Besitzer lesbar)"

umask 022
curl -fsSL "$REPO_RAW/docker/templates/docker-compose.yml" -o docker-compose.yml 2>/dev/null \
    || die "docker-compose.yml konnte nicht geladen werden von $REPO_RAW"
# update.sh ist optional: fehlt es, laesst sich die Installation trotzdem betreiben.
if curl -fsSL "$REPO_RAW/update.sh" -o update.sh 2>/dev/null; then
    chmod +x update.sh 2>/dev/null || warn "update.sh konnte nicht ausfuehrbar gemacht werden."
else
    warn "update.sh konnte nicht geladen werden - Updates vorerst von Hand."
fi
ok "docker-compose.yml geschrieben"

# ------------------------------------------------------------------ Start ----

info "Images laden und starten"
docker compose pull
docker compose up -d

info "Auf Bereitschaft warten"
_waited=0
while [ "$_waited" -lt 180 ]; do
    if docker compose exec -T app php artisan migrate:status >/dev/null 2>&1; then
        break
    fi
    sleep 3
    _waited=$((_waited + 3))
done

[ "$_waited" -lt 180 ] || die "Die App wurde nicht bereit. Logs: docker compose logs app"
ok "App laeuft"

# -------------------------------------------------------------- Bootstrap ----

INVITE_OUTPUT=""
if [ "$MODE_LABEL" = "Neuinstallation" ]; then
    info "Einladungscode fuer die erste Registrierung erzeugen"
    INVITE_OUTPUT=$(docker compose exec -T app php artisan invite:manage --create --count=1 --note="install.sh" 2>/dev/null || true)
fi

# --------------------------------------------------------------- Abschluss ---

say ""
info "Fertig"
say ""
if [ "${NO_PROXY_NOTE:-}" = "yes" ]; then
    say "  Panel:        http://$APP_BIND:$APP_PORT (nur lokal)"
    say "                Ohne Reverse Proxy ist die App absichtlich nur auf dem"
    say "                Loopback erreichbar. Per SSH-Tunnel testen:"
    say "                  ssh -L $APP_PORT:$APP_BIND:$APP_PORT <server>"
else
    say "  Panel:        https://$APP_HOST"
fi
if [ -n "$INVITE_OUTPUT" ]; then
    say "  Registrieren: https://$APP_HOST/register"
    say ""
    printf '%s\n' "$INVITE_OUTPUT"
    say ""
    say "  Danach den ersten Nutzer zum Admin machen:"
    say "    cd $RR_DIR && docker compose exec -T app php artisan user:manage DEINE@MAIL --admin --verify"
fi
say ""
say "  DNS:          A  $APP_HOST            -> IP dieses Servers"
if [ -n "$STREAM_DOMAIN" ]; then
    say "                A  *.$STREAM_DOMAIN  -> IP dieses Servers"
    say "  Firewall:     ufw allow $STREAM_PORT_MIN:$STREAM_PORT_MAX/tcp"
fi
say ""
warn "RadioRing steuert Docker auf diesem Host. Wer die App kompromittiert, wird root."
warn "Bitte SECURITY.md lesen, bevor die Instanz oeffentlich erreichbar ist."
say ""
say "  Aktualisieren: cd $RR_DIR && ./update.sh"
say ""
