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

  -y, --yes        Non-interactive, accepts every default
      --dir=PATH   Target directory (default: /opt/radioring)

Every prompt can be preseeded through an environment variable, for example:
  RR_APP_HOST=panel.example.com RR_DB=bundled sh install.sh --yes
USAGE
            exit 0
            ;;
    esac
done

# ----------------------------------------------------------------- Output ----

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

# ------------------------------------------------------------------ Input ----

have_tty() { [ -e /dev/tty ] && [ "$ASSUME_YES" != "1" ]; }

# ask VARNAME "Question" "Default"
#
# POSIX sh has no name references, so the assignment goes through eval.
# _preset and _answer are cleared literally beforehand: sh has no local
# variables, and a value from an earlier call would otherwise bleed through.
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

# ask_yes VARNAME "Question" "y|n"
#
# j and ja stay accepted so that presets written for the earlier German
# version of this installer keep working.
ask_yes() {
    ask "$1" "$2 (y/n)" "$3"
    _v=''
    eval "_v=\$$1"
    case "$_v" in
        y|Y|yes|j|J|ja|true|1) eval "$1=yes" ;;
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

# ----------------------------------------------------------- Prerequisites ----

info "Checking prerequisites"

case "$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ok "Architecture $(uname -m)" ;;
    *) warn "Architecture $(uname -m) is untested. Images exist for amd64 and arm64." ;;
esac

if ! command -v docker >/dev/null 2>&1; then
    warn "Docker is not installed."
    ask_yes INSTALL_DOCKER "Install Docker now via get.docker.com?" "y"
    if [ "$INSTALL_DOCKER" = "yes" ]; then
        curl -fsSL https://get.docker.com | sh || die "Docker installation failed."
    else
        die "Docker is required. Instructions: https://docs.docker.com/engine/install/"
    fi
fi

docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is missing (docker compose version)."
docker info >/dev/null 2>&1 || die "No access to the Docker daemon. Run as root or join the docker group."
ok "Docker ready"

# ------------------------------------------------------- Existing install ----

FRESH_INSTALL=1
MODE_LABEL="New installation"
if [ -f "$RR_DIR/.env" ]; then
    FRESH_INSTALL=0
    MODE_LABEL="Repair / update"
    info "Found an existing installation in $RR_DIR"
    # Existing values become the defaults. APP_KEY is NEVER regenerated.
    # shellcheck disable=SC1091
    . "$RR_DIR/.env" 2>/dev/null || true
    cp "$RR_DIR/.env" "$RR_DIR/.env.bak.$(date +%s)"
    ok "Backed up the previous .env"
fi

# ---------------------------------------------------------------- Prompts ----

info "$MODE_LABEL - configuration"

ask APP_HOST       "Panel domain"                           "${APP_HOST:-panel.example.com}"
ask IMAGE_OWNER    "GitHub owner of the images"             "${IMAGE_OWNER:-radioring}"
ask IMAGE_TAG      "Image tag"                              "${IMAGE_TAG:-latest}"

ask CONTAINER_DRIVER "Container control (docker|portainer)" "${CONTAINER_DRIVER:-docker}"
if [ "$CONTAINER_DRIVER" = "portainer" ]; then
    ask PORTAINER_ENDPOINT    "Portainer API endpoint"      "${PORTAINER_ENDPOINT:-https://portainer.example.com/api}"
    ask PORTAINER_TOKEN       "Portainer API token"         "${PORTAINER_TOKEN:-}"
    ask PORTAINER_ENVIRONMENT "Portainer environment ID"    "${PORTAINER_ENVIRONMENT:-2}"
fi

ask RR_DB "Database: bundled or external" "${RR_DB:-bundled}"
if [ "$RR_DB" = "external" ]; then
    ask DB_HOST     "MySQL host"     "${DB_HOST:-}"
    ask DB_PORT     "MySQL port"     "${DB_PORT:-3306}"
    ask DB_DATABASE "Database name"  "${DB_DATABASE:-radioring}"
    ask DB_USERNAME "User"           "${DB_USERNAME:-radioring}"
    ask DB_PASSWORD "Password"       "${DB_PASSWORD:-}"
else
    DB_HOST="mysql"; DB_PORT="3306"
    DB_DATABASE="${DB_DATABASE:-radioring}"
    DB_USERNAME="${DB_USERNAME:-radioring}"
    DB_PASSWORD="${DB_PASSWORD:-$(random_secret)}"
    DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$(random_secret)}"
fi

ask RR_REDIS "Redis: bundled or external" "${RR_REDIS:-bundled}"
if [ "$RR_REDIS" = "external" ]; then
    ask REDIS_HOST     "Redis host"     "${REDIS_HOST:-}"
    ask REDIS_PORT     "Redis port"     "${REDIS_PORT:-6379}"
    ask REDIS_PASSWORD "Redis password" "${REDIS_PASSWORD:-}"
else
    REDIS_HOST="redis"; REDIS_PORT="6379"
    REDIS_PASSWORD="${REDIS_PASSWORD:-$(random_secret)}"
fi

ask RR_PROXY "Reverse proxy: traefik, external or none" "${RR_PROXY:-traefik}"
case "$RR_PROXY" in
    traefik)
        ask ACME_EMAIL "Email for Let's Encrypt" "${ACME_EMAIL:-admin@$APP_HOST}"
        WEB_NETWORK="radioring-web"; WEB_NETWORK_EXTERNAL="false"; TRAEFIK_ENABLE="true"
        ;;
    external)
        ask WEB_NETWORK "Name of the existing Traefik network" "${WEB_NETWORK:-web}"
        ask TRAEFIK_CERTRESOLVER "Cert resolver of your Traefik instance" "${TRAEFIK_CERTRESOLVER:-radioring}"
        WEB_NETWORK_EXTERNAL="true"; TRAEFIK_ENABLE="true"; ACME_EMAIL="unused@example.com"
        ;;
    *)
        WEB_NETWORK="radioring-web"; WEB_NETWORK_EXTERNAL="false"; TRAEFIK_ENABLE="false"
        ACME_EMAIL="unused@example.com"
        NO_PROXY_NOTE="yes"
        ;;
esac

# Without a reverse proxy there is no other way into the app. Loopback only, on
# purpose: a directly published port would be unprotected and would also
# undercut trustProxies(at: '*'). See SECURITY.md.
APP_BIND="${APP_BIND:-127.0.0.1}"
APP_PORT="${APP_PORT:-8080}"

ask STREAM_DOMAIN   "Domain for the live input (empty = no live ingest)" "${STREAM_DOMAIN:-stream.example.com}"
ask STREAM_PORT_MIN "First port for the live input" "${STREAM_PORT_MIN:-8001}"
ask STREAM_PORT_MAX "Last port for the live input"  "${STREAM_PORT_MAX:-8099}"

ask RADIORING_MODE "Operating mode (standalone|cloud)" "${RADIORING_MODE:-standalone}"

# ---------------------------------------------------------------- Profiles ----

PROFILES="";
[ "$CONTAINER_DRIVER" = "docker" ] && PROFILES="driver-docker"
[ "$RR_DB" = "bundled" ]    && PROFILES="${PROFILES:+$PROFILES,}bundled-db"
[ "$RR_REDIS" = "bundled" ] && PROFILES="${PROFILES:+$PROFILES,}bundled-redis"
[ "$RR_PROXY" = "traefik" ] && PROFILES="${PROFILES:+$PROFILES,}traefik"

APP_KEY="${APP_KEY:-$(app_key)}"

# ----------------------------------------------------------------- Writing ----

info "Writing the configuration to $RR_DIR"
mkdir -p "$RR_DIR"
cd "$RR_DIR"

# .env holds the database and Redis passwords.
umask 077

cat > .env <<ENVEOF
# Generated by install.sh. Changes here take effect after 'docker compose up -d'.
APP_NAME=RadioRing
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$APP_HOST
ASSET_URL=https://$APP_HOST
APP_MODE=all
APP_LOCALE=de
APP_FALLBACK_LOCALE=en

# Initial value. The effective mode lives in the database and can be switched
# under /admin/settings without another deployment.
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

# Container control. Careful: anyone who reaches the Docker API is effectively
# root on the host. See SECURITY.md.
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

# The station container reaches the app on the internal network.
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

# Source update.sh pulls the docker-compose.yml from. On a fork this points at
# the fork, not at the original.
RR_REPO_RAW=$REPO_RAW
ENVEOF

# umask alone is not dependable: set it explicitly, the file holds the database
# and Redis passwords.
chmod 600 .env 2>/dev/null || warn "Could not set the permissions of .env to 600."
ok ".env written (readable by its owner only)"

umask 022
curl -fsSL "$REPO_RAW/docker/templates/docker-compose.yml" -o docker-compose.yml 2>/dev/null \
    || die "Could not download docker-compose.yml from $REPO_RAW"
# update.sh is optional: without it the installation still runs.
if curl -fsSL "$REPO_RAW/update.sh" -o update.sh 2>/dev/null; then
    chmod +x update.sh 2>/dev/null || warn "Could not make update.sh executable."
else
    warn "Could not download update.sh - updates have to be run by hand for now."
fi
ok "docker-compose.yml written"

# ------------------------------------------------------------------- Start ----

info "Pulling images and starting"
docker compose pull
docker compose up -d

info "Waiting for readiness"
_waited=0
while [ "$_waited" -lt 180 ]; do
    if docker compose exec -T app php artisan migrate:status >/dev/null 2>&1; then
        break
    fi
    sleep 3
    _waited=$((_waited + 3))
done

[ "$_waited" -lt 180 ] || die "The app did not become ready. Logs: docker compose logs app"
ok "App is running"

# --------------------------------------------------------------- Bootstrap ----

INVITE_OUTPUT=""
if [ "$FRESH_INSTALL" = "1" ]; then
    info "Creating an invite code for the first registration"
    INVITE_OUTPUT=$(docker compose exec -T app php artisan invite:manage --create --count=1 --note="install.sh" 2>/dev/null || true)
fi

# ---------------------------------------------------------------- Finished ----

say ""
info "Done"
say ""
if [ "${NO_PROXY_NOTE:-}" = "yes" ]; then
    say "  Panel:        http://$APP_BIND:$APP_PORT (local only)"
    say "                Without a reverse proxy the app is deliberately reachable"
    say "                on the loopback only. Test it through an SSH tunnel:"
    say "                  ssh -L $APP_PORT:$APP_BIND:$APP_PORT <server>"
else
    say "  Panel:        https://$APP_HOST"
fi
if [ -n "$INVITE_OUTPUT" ]; then
    say "  Register:     https://$APP_HOST/register"
    say ""
    printf '%s\n' "$INVITE_OUTPUT"
    say ""
    say "  Then make the first user an admin:"
    say "    cd $RR_DIR && docker compose exec -T app php artisan user:manage YOUR@MAIL --admin --verify"
fi
say ""
say "  DNS:          A  $APP_HOST            -> IP of this server"
if [ -n "$STREAM_DOMAIN" ]; then
    say "                A  *.$STREAM_DOMAIN  -> IP of this server"
    say "  Firewall:     ufw allow $STREAM_PORT_MIN:$STREAM_PORT_MAX/tcp"
fi
say ""
warn "RadioRing controls Docker on this host. Compromising the app means root."
warn "Please read SECURITY.md before the instance is reachable from the internet."
say ""
say "  Update:       cd $RR_DIR && ./update.sh"
say ""
