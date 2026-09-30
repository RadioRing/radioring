#!/bin/sh
# RadioRing support check. Sits next to the docker-compose.yml of an installation.
#
#   ./support.sh                 Check the installation, print hints
#   ./support.sh --report        Also write a redacted report file to send along
#   ./support.sh --report=FILE   Same, into FILE
#   ./support.sh --offline       Skip the DNS and HTTPS checks from this host
#   ./support.sh --dir=PATH      Installation directory (default: next to this script)
#
# Everything here only reads. It changes no file, no container and no database
# row, so it is safe to run on a live station at any time.
#
# The report is meant to be sent to somebody who has no access to the server.
# Every secret from the .env is replaced by *** before it reaches the file, and
# so are station tokens in log lines. Read the file before you send it anyway:
# log lines can carry anything the application logged.
#
# POSIX sh on purpose, like install.sh and update.sh. Every docker command gets
# </dev/null so that 'curl ... | sh' keeps working.
set -u

RR_DIR="${RR_DIR:-}"
REPORT=""
OFFLINE=0

for _arg in "$@"; do
    case "$_arg" in
        --report) REPORT="support-report-$(date +%Y%m%d-%H%M%S).txt" ;;
        --report=*) REPORT="${_arg#--report=}" ;;
        --offline) OFFLINE=1 ;;
        --dir=*) RR_DIR="${_arg#--dir=}" ;;
        -h|--help)
            sed -n '2,19p' "$0" 2>/dev/null | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) echo "Unknown option: $_arg" >&2; exit 2 ;;
    esac
done

# Next to this script first, because that is where install.sh puts it. Under
# 'curl | sh' $0 is "sh", so fall back to the installer's default.
if [ -z "$RR_DIR" ]; then
    _self_dir="$(cd "$(dirname "$0")" 2>/dev/null && pwd)" || _self_dir=""
    if [ -n "$_self_dir" ] && [ -f "$_self_dir/docker-compose.yml" ]; then
        RR_DIR="$_self_dir"
    else
        RR_DIR="/opt/radioring"
    fi
fi

# ----------------------------------------------------------------- Output ----

if [ -t 1 ]; then
    C_RESET=$(printf '\033[0m'); C_BOLD=$(printf '\033[1m'); C_DIM=$(printf '\033[2m')
    C_RED=$(printf '\033[31m'); C_GREEN=$(printf '\033[32m'); C_YELLOW=$(printf '\033[33m')
else
    C_RESET=''; C_BOLD=''; C_DIM=''; C_RED=''; C_GREEN=''; C_YELLOW=''
fi

COUNT_OK=0
COUNT_WARN=0
COUNT_FAIL=0

# Secrets to mask in the report, one per line. Filled once the .env is read.
RR_SECRETS=""
export RR_SECRETS

# Replaces every known secret, and station tokens that the .env does not know
# about, with ***. awk with index() rather than sed: the values are base64 and
# may hold slashes and plus signs that sed would read as syntax.
redact() {
    awk '
        BEGIN { n = split(ENVIRON["RR_SECRETS"], secrets, "\n") }
        {
            for (k = 1; k <= n; k++) {
                s = secrets[k]
                if (length(s) < 6) { continue }
                while ((i = index($0, s)) > 0) {
                    $0 = substr($0, 1, i - 1) "***" substr($0, i + length(s))
                }
            }
            gsub(/[Tt]oken=[^&[:space:]"]+/, "token=***")
            gsub(/Bearer [^[:space:]"]+/, "Bearer ***")
            print
        }'
}

# Appends plain text to the report, if one is being written.
to_report() {
    [ -n "$REPORT" ] || return 0
    printf '%s\n' "$*" | redact >> "$REPORT"
}

# Appends the output of a command to the report only, under a heading.
report_block() {
    [ -n "$REPORT" ] || return 0
    _title="$1"; shift
    {
        printf '\n----- %s -----\n' "$_title"
        "$@" 2>&1 </dev/null
    } | redact >> "$REPORT"
}

section() {
    printf '\n%s==> %s%s\n' "$C_BOLD" "$*" "$C_RESET"
    to_report ""
    to_report "==> $*"
}

ok() {
    COUNT_OK=$((COUNT_OK + 1))
    printf '%s  ok%s %s\n' "$C_GREEN" "$C_RESET" "$*"
    to_report "  ok $*"
}

warn() {
    COUNT_WARN=$((COUNT_WARN + 1))
    printf '%s  !!%s %s\n' "$C_YELLOW" "$C_RESET" "$*"
    to_report "  !! $*"
}

fail() {
    COUNT_FAIL=$((COUNT_FAIL + 1))
    printf '%s  xx%s %s\n' "$C_RED" "$C_RESET" "$*"
    to_report "  xx $*"
}

info() {
    printf '     %s\n' "$*"
    to_report "     $*"
}

# A hint belongs to the check right above it: what to look at or run next.
hint() {
    printf '%s     -> %s%s\n' "$C_DIM" "$*" "$C_RESET"
    to_report "     -> $*"
}

have() { command -v "$1" >/dev/null 2>&1; }

# Exit code 1 as soon as one check failed, so that a provisioning script can
# run this right after install.sh and stop on it.
summary_and_exit() {
    section "Summary"

    if [ "$COUNT_FAIL" -gt 0 ]; then
        _colour="$C_RED"
    elif [ "$COUNT_WARN" -gt 0 ]; then
        _colour="$C_YELLOW"
    else
        _colour="$C_GREEN"
    fi

    printf '%s%d ok, %d warnings, %d errors%s\n' "$_colour" "$COUNT_OK" "$COUNT_WARN" "$COUNT_FAIL" "$C_RESET"
    to_report "$COUNT_OK ok, $COUNT_WARN warnings, $COUNT_FAIL errors"

    if [ -n "$REPORT" ]; then
        printf '\nReport written to %s\n' "$REPORT"
        printf 'Secrets from .env are masked. Read it once before you send it on.\n'
    else
        printf '\nNeed help? ./support.sh --report writes all of this, plus logs, into one file to send along.\n'
    fi

    [ "$COUNT_FAIL" -eq 0 ] && exit 0
    exit 1
}

# ------------------------------------------------------------------- .env ----

# Read a single value out of the .env without sourcing it, same as update.sh.
env_get() {
    grep -E "^$1=" "$RR_DIR/.env" 2>/dev/null | head -n 1 | cut -d= -f2- || true
}

# Is $2 one of the comma separated words in $1?
list_has() {
    case ",$1," in
        *",$2,"*) return 0 ;;
    esac
    return 1
}

# -------------------------------------------------------------- Docker ----

dc() { docker compose "$@" </dev/null; }

# Container id of a compose service, empty when it does not exist.
service_id() { dc ps -a -q "$1" 2>/dev/null | head -n 1; }

inspect() { docker inspect -f "$2" "$1" 2>/dev/null </dev/null; }

# Space separated list of the networks a container is attached to.
networks_of() {
    # shellcheck disable=SC2016 # a Go template for docker, not shell
    inspect "$1" '{{range $name, $net := .NetworkSettings.Networks}}{{$name}} {{end}}'
}

on_network() {
    case " $(networks_of "$1") " in
        *" $2 "*) return 0 ;;
    esac
    return 1
}

# Runs a command inside the app container. -T because there is no terminal
# under 'curl | sh', and none is needed.
in_app() { dc exec -T app "$@" 2>&1; }

# ===================================================================== Run ====

if [ -n "$REPORT" ]; then
    case "$REPORT" in
        /*) ;;
        *) REPORT="$(pwd)/$REPORT" ;;
    esac
    umask 077
    : > "$REPORT" || { echo "Cannot write $REPORT" >&2; exit 2; }
    umask 022
fi

printf '%sRadioRing support check%s\n' "$C_BOLD" "$C_RESET"
to_report "RadioRing support check"
info "Date:        $(date '+%Y-%m-%d %H:%M:%S %Z')"
info "Directory:   $RR_DIR"

# ------------------------------------------------------------------ Host ----

section "Host"

info "System:      $(uname -srm 2>/dev/null)"
if [ -r /etc/os-release ]; then
    info "OS:          $(sed -n 's/^PRETTY_NAME="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /etc/os-release)"
fi

case "$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ok "Architecture $(uname -m) is supported" ;;
    *) warn "Architecture $(uname -m) is untested. Images exist for amd64 and arm64." ;;
esac

if have nproc; then
    _cpus="$(nproc)"
    info "CPUs:        $_cpus, load $(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
fi

if [ -r /proc/meminfo ]; then
    _mem_total=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo)
    _mem_avail=$(awk '/^MemAvailable:/ { print int($2 / 1024) }' /proc/meminfo)
    info "Memory:      ${_mem_avail:-?} MB available of ${_mem_total:-?} MB"
    if [ -n "$_mem_avail" ] && [ "$_mem_avail" -lt 256 ]; then
        warn "Less than 256 MB of memory available. Containers may be killed by the kernel."
    fi
fi

# Checks free space where the path lives. $1 path, $2 label.
check_disk() {
    _line="$(df -Pk "$1" 2>/dev/null | awk 'NR == 2 { print $4, $5 }')"
    [ -n "$_line" ] || return 0
    _free_mb=$(( ${_line%% *} / 1024 ))
    _used="${_line##* }"
    if [ "$_free_mb" -lt 1024 ]; then
        fail "$2: only $_free_mb MB free ($_used used, $1)"
        hint "Uploads, backups and image pulls fail when the disk is full. 'docker image prune' frees old images."
    elif [ "$_free_mb" -lt 5120 ]; then
        warn "$2: $_free_mb MB free ($_used used, $1)"
    else
        ok "$2: $_free_mb MB free ($_used used)"
    fi
}

[ -d "$RR_DIR" ] && check_disk "$RR_DIR" "Disk of the installation"

# A playout system runs on the wall clock: a drifting clock means hours that
# start late and hard cuts at the wrong second.
if have timedatectl; then
    _ntp="$(timedatectl show -p NTPSynchronized --value 2>/dev/null || true)"
    _tz="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
    [ -n "$_tz" ] && info "Timezone:    $_tz (the station timezone is set in the panel, not here)"
    case "$_ntp" in
        yes) ok "System clock is synchronised (NTP)" ;;
        no)
            warn "System clock is not synchronised. Hard starts and hour changes rely on it."
            hint "timedatectl set-ntp true"
            ;;
    esac
fi

# ---------------------------------------------------------------- Docker ----

section "Docker"

if ! have docker; then
    fail "Docker is not installed."
    hint "Run install.sh, it offers to install Docker."
    DOCKER_OK=0
elif ! docker info >/dev/null 2>&1 </dev/null; then
    fail "No access to the Docker daemon."
    hint "Run as root or as a member of the docker group. Is the daemon running? systemctl status docker"
    DOCKER_OK=0
else
    DOCKER_OK=1
    ok "Docker $(docker version -f '{{.Server.Version}}' 2>/dev/null </dev/null) is running"

    _engine_api="$(docker version -f '{{.Server.APIVersion}}' 2>/dev/null </dev/null || true)"
    if printf '%s\n' "$_engine_api" | awk -F. '{ exit !($1 > 1 || ($1 == 1 && $2 >= 44)) }'; then
        ok "Docker Engine API $_engine_api (1.44 needed)"
    else
        fail "Docker Engine API ${_engine_api:-unknown} is below 1.44. RadioRing needs Docker 25 or newer."
        hint "Distribution packages often lag behind: https://docs.docker.com/engine/install/"
    fi

    if _compose="$(docker compose version --short 2>/dev/null </dev/null)"; then
        ok "Docker Compose $_compose"
    else
        fail "Docker Compose v2 is missing (docker compose version)."
        DOCKER_OK=0
    fi

    _docker_root="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null </dev/null || true)"
    [ -n "$_docker_root" ] && [ -d "$_docker_root" ] && check_disk "$_docker_root" "Disk of Docker"
fi

# ---------------------------------------------------------- Installation ----

section "Installation"

INSTALL_OK=1
if [ ! -f "$RR_DIR/.env" ] || [ ! -f "$RR_DIR/docker-compose.yml" ]; then
    fail "No RadioRing installation in $RR_DIR (.env or docker-compose.yml missing)."
    hint "Other directory? ./support.sh --dir=/path/to/radioring"
    INSTALL_OK=0
else
    ok ".env and docker-compose.yml found"
    cd "$RR_DIR" || exit 2

    # Collect the secrets first, so that nothing below can leak them into the report.
    RR_SECRETS="$(grep -E '^[A-Z0-9_]*(PASSWORD|SECRET|TOKEN|_KEY)=' .env | cut -d= -f2- | grep -v '^$' || true)"
    export RR_SECRETS

    _perm="$(stat -c '%a' .env 2>/dev/null || true)"
    case "$_perm" in
        600|400) ok ".env is readable by its owner only" ;;
        '') ;;
        *)
            warn ".env has the permissions $_perm. It holds the database and Redis passwords."
            hint "chmod 600 $RR_DIR/.env"
            ;;
    esac

    RR_CHANNEL="$(env_get RR_CHANNEL)"
    RR_VERSION="$(env_get RR_VERSION)"
    RADIORING_IMAGE="$(env_get RADIORING_IMAGE)"
    STATION_IMAGE="$(env_get STATION_IMAGE)"
    ICECAST_IMAGE="$(env_get ICECAST_IMAGE)"
    COMPOSE_PROFILES="$(env_get COMPOSE_PROFILES)"
    CONTAINER_DRIVER="$(env_get CONTAINER_DRIVER)"
    APP_HOST="$(env_get APP_HOST)"
    APP_MODE="$(env_get APP_MODE)"
    APP_BIND="$(env_get APP_BIND)"
    APP_PORT="$(env_get APP_PORT)"
    WEB_NETWORK="$(env_get WEB_NETWORK)"
    TRAEFIK_ENABLE="$(env_get TRAEFIK_ENABLE)"
    STREAM_DOMAIN="$(env_get STREAM_DOMAIN)"
    STREAM_PORT_MIN="$(env_get STREAM_PORT_MIN)"
    STREAM_PORT_MAX="$(env_get STREAM_PORT_MAX)"
    MANAGED_BY="$(env_get STATION_MANAGED_BY)"
    STATION_NETWORK="$(env_get DOCKER_STATION_NETWORK)"
    STREAM_NETWORK="$(env_get DOCKER_STREAM_NETWORK)"
    DOCKER_WEB_NETWORK="$(env_get DOCKER_WEB_NETWORK)"

    [ -n "$APP_MODE" ] || APP_MODE=all
    [ -n "$APP_BIND" ] || APP_BIND=127.0.0.1
    [ -n "$APP_PORT" ] || APP_PORT=8080
    [ -n "$WEB_NETWORK" ] || WEB_NETWORK=radioring-web
    [ -n "$MANAGED_BY" ] || MANAGED_BY=radioring
    [ -n "$STATION_NETWORK" ] || STATION_NETWORK=radioring
    [ -n "$DOCKER_WEB_NETWORK" ] || DOCKER_WEB_NETWORK="$WEB_NETWORK"

    info "Channel:     ${RR_CHANNEL:-?}, version ${RR_VERSION:-?}"
    info "App image:   ${RADIORING_IMAGE:-?}"
    info "Profiles:    ${COMPOSE_PROFILES:-none}"
    info "Driver:      ${CONTAINER_DRIVER:-?}"
    info "Panel:       ${APP_HOST:-?}"

    _missing=""
    for _key in APP_KEY APP_URL APP_HOST DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD \
                REDIS_HOST RADIORING_IMAGE STATION_IMAGE LIQUIDSOAP_API_URL CONTAINER_DRIVER; do
        [ -n "$(env_get "$_key")" ] || _missing="$_missing $_key"
    done
    if [ -n "$_missing" ]; then
        fail "Missing values in .env:$_missing"
        hint "Rerun ./install.sh, it repairs the .env and keeps what is already there."
    else
        ok "All required values are set in .env"
    fi

    case "$(env_get APP_KEY)" in
        base64:*) ;;
        '') ;;
        *) warn "APP_KEY does not look like a generated key (base64:...)." ;;
    esac

    case "$(env_get APP_DEBUG)" in
        true|1)
            warn "APP_DEBUG is on. Error pages then show the environment, passwords included."
            hint "Set APP_DEBUG=false in .env and run 'docker compose up -d'."
            ;;
    esac

    case "$APP_HOST" in
        ''|panel.example.com)
            fail "APP_HOST is still the placeholder '$APP_HOST'."
            hint "Rerun ./install.sh and enter the real panel domain."
            ;;
    esac

    # update.sh moves images and template together. A hand edited image line
    # is how an installation ends up with an app and a template from different releases.
    if [ "$RR_CHANNEL" = "edge" ]; then
        _want_tag="edge"
    else
        _want_tag="${RR_VERSION#v}"
    fi
    _drift=""
    for _image in "$RADIORING_IMAGE" "$STATION_IMAGE" "$ICECAST_IMAGE"; do
        [ -n "$_image" ] || continue
        [ "${_image##*:}" = "$_want_tag" ] || _drift="$_drift ${_image##*/}"
    done
    if [ -n "$_want_tag" ] && [ -n "$_drift" ]; then
        warn "Image tags do not match version ${RR_VERSION:-?}:$_drift"
        hint "./update.sh --version=${RR_VERSION:-vX.Y.Z} puts images and template back on one release."
    elif [ -n "$_want_tag" ]; then
        ok "Image tags match version $RR_VERSION"
    fi

    if [ "$DOCKER_OK" = "1" ]; then
        if _cfg_err="$(dc config --quiet 2>&1)"; then
            ok "docker-compose.yml is valid"
        else
            fail "docker-compose.yml does not parse:"
            printf '%s\n' "$_cfg_err" | head -n 5 | while IFS= read -r _l; do info "$_l"; done
            INSTALL_OK=0
        fi
    fi
fi

if [ "$DOCKER_OK" != "1" ] || [ "$INSTALL_OK" != "1" ]; then
    fail "Stopped early: without Docker or an installation there is nothing more to check."
    [ -f "$RR_DIR/.env" ] && report_block ".env (secrets masked)" \
        sed -E 's/^([A-Z0-9_]*(PASSWORD|SECRET|TOKEN|_KEY)=).+/\1***/' "$RR_DIR/.env"
    summary_and_exit
fi

# -------------------------------------------------------------- Services ----

section "Services"

# The services compose would run with the profiles from the .env. Anything else
# in the template belongs to a profile that is switched off on purpose.
EXPECTED_SERVICES="$(dc config --services 2>/dev/null | tr '\n' ' ')"

for _svc in $EXPECTED_SERVICES; do
    _id="$(service_id "$_svc")"

    if [ -z "$_id" ]; then
        fail "$_svc: no container"
        hint "docker compose up -d"
        continue
    fi

    _state="$(inspect "$_id" '{{.State.Status}}')"
    _health="$(inspect "$_id" '{{if .State.Health}}{{.State.Health.Status}}{{end}}')"
    _restarts="$(inspect "$_id" '{{.RestartCount}}')"
    _oom="$(inspect "$_id" '{{.State.OOMKilled}}')"
    _exit="$(inspect "$_id" '{{.State.ExitCode}}')"
    _started="$(inspect "$_id" '{{.State.StartedAt}}' | cut -c1-19 | tr 'T' ' ')"

    case "$_state/$_health" in
        running/healthy|running/)
            ok "$_svc: running since $_started UTC${_health:+, $_health}"
            ;;
        running/starting)
            warn "$_svc: running, health check still starting"
            hint "Wait a minute and run this again. The app migrates the database on every start."
            ;;
        running/unhealthy)
            fail "$_svc: running but unhealthy"
            hint "docker compose logs --tail=100 $_svc"
            ;;
        restarting/*)
            fail "$_svc: keeps restarting (exit code $_exit)"
            hint "docker compose logs --tail=100 $_svc"
            ;;
        *)
            fail "$_svc: $_state (exit code $_exit)"
            hint "docker compose up -d $_svc, then docker compose logs --tail=100 $_svc"
            ;;
    esac

    if [ "$_oom" = "true" ]; then
        fail "$_svc was killed for lack of memory (OOM)."
    fi
    if [ -n "$_restarts" ] && [ "$_restarts" -gt 0 ] 2>/dev/null; then
        warn "$_svc restarted $_restarts times on its own"
    fi

    # The two errors that make up most failed first starts, spelled out.
    if [ "$_state" != "running" ] || [ "$_health" = "unhealthy" ]; then
        _logs="$(dc logs --tail=200 "$_svc" 2>&1)"
        case "$_logs" in
            *"address already in use"*|*"port is already allocated"*)
                hint "A port is taken by something else on this host (another web server?). 'ss -ltnp' shows who."
                ;;
        esac
        case "$_logs" in
            *"Access denied for user"*)
                hint "The database rejects the credentials from .env. A bundled MySQL keeps the password it was first created with."
                ;;
        esac
    fi
done

# Only the app image is compared: MySQL, Redis and Traefik are pinned in the template.
APP_ID="$(service_id app)"
if [ -n "$APP_ID" ] && [ -n "$RADIORING_IMAGE" ]; then
    _running_image="$(inspect "$APP_ID" '{{.Config.Image}}')"
    if [ "$_running_image" != "$RADIORING_IMAGE" ]; then
        warn "The app runs $_running_image, .env says $RADIORING_IMAGE"
        hint "docker compose up -d"
    fi
fi

# --------------------------------------------------------------- Networks ----

section "Networks"

network_exists() { docker network inspect "$1" >/dev/null 2>&1 </dev/null; }

if network_exists radioring; then
    ok "Network radioring exists"
else
    fail "Network radioring is missing. The app, the database and the stations talk over it."
    hint "docker compose up -d creates it."
fi

if network_exists "$WEB_NETWORK"; then
    ok "Network $WEB_NETWORK exists (reverse proxy side)"
else
    fail "Network $WEB_NETWORK is missing."
    if [ "$(env_get WEB_NETWORK_EXTERNAL)" = "true" ]; then
        hint "It is marked external: it has to be the network your own Traefik already uses. 'docker network ls' lists them."
    else
        hint "docker compose up -d creates it."
    fi
fi

if [ -n "$APP_ID" ]; then
    info "App is on:   $(networks_of "$APP_ID")"

    # Station containers call LIQUIDSOAP_API_URL (http://app:8080), which only
    # resolves on a network they share with the app.
    if on_network "$APP_ID" "$STATION_NETWORK"; then
        ok "App is on the station network $STATION_NETWORK"
    else
        fail "App is not on DOCKER_STATION_NETWORK=$STATION_NETWORK. Stations cannot reach the app."
        hint "DOCKER_STATION_NETWORK should be radioring. Change it in .env and run 'docker compose up -d'."
    fi

    if [ "$TRAEFIK_ENABLE" = "true" ]; then
        if on_network "$APP_ID" "$WEB_NETWORK"; then
            ok "App is on the proxy network $WEB_NETWORK"
        else
            fail "App is not on $WEB_NETWORK. The reverse proxy cannot reach it (Bad Gateway)."
        fi
    fi

    # The listener figures come straight from the sidecars.
    if [ -n "$STREAM_NETWORK" ]; then
        if on_network "$APP_ID" "$STREAM_NETWORK"; then
            ok "App is on the stream network $STREAM_NETWORK"
        else
            warn "App is not on DOCKER_STREAM_NETWORK=$STREAM_NETWORK. Listener figures stay empty."
            hint "Compare docker-compose.yml with the template of your release; ./update.sh restores it."
        fi
    else
        info "DOCKER_STREAM_NETWORK is not set, the internal Icecast is not offered."
    fi
fi

# Isolation: whoever reaches the app's socket proxy (POST=1) is root on the host,
# and the read-only one of Traefik still hands out every container's environment.
# Neither may share a network with anything that processes outside input.
_proxy_id="$(service_id dockerproxy)"
if [ -n "$_proxy_id" ]; then
    case " $(networks_of "$_proxy_id") " in
        " radioring-docker ") ok "The socket proxy sits alone with the app in radioring-docker" ;;
        *)
            fail "The socket proxy is on: $(networks_of "$_proxy_id"). Anything there can take over the host."
            hint "Compare docker-compose.yml with the template of your release; ./update.sh restores it."
            ;;
    esac
fi

_proxy_id="$(service_id dockerproxy-traefik)"
if [ -n "$_proxy_id" ]; then
    case " $(networks_of "$_proxy_id") " in
        " radioring-traefik-docker ") ok "Traefik's socket proxy sits alone with Traefik" ;;
        *)
            fail "Traefik's socket proxy is on: $(networks_of "$_proxy_id"). Anything there can read every container's secrets."
            hint "Compare docker-compose.yml with the template of your release; ./update.sh restores it."
            ;;
    esac
fi

# The Icecast sidecars join DOCKER_WEB_NETWORK and are only public if Traefik
# watches that very network.
TRAEFIK_ID=""
list_has "$COMPOSE_PROFILES" traefik && TRAEFIK_ID="$(service_id traefik)"
if [ -n "$TRAEFIK_ID" ]; then
    if on_network "$TRAEFIK_ID" "$DOCKER_WEB_NETWORK"; then
        ok "Traefik is on $DOCKER_WEB_NETWORK, where the Icecast sidecars go"
    else
        warn "Traefik is not on DOCKER_WEB_NETWORK=$DOCKER_WEB_NETWORK. Internal Icecast streams stay unreachable."
        hint "Set DOCKER_WEB_NETWORK=$WEB_NETWORK in .env"
    fi

    if on_network "$TRAEFIK_ID" "$STATION_NETWORK"; then
        warn "Traefik is on the internal network $STATION_NETWORK. It faces the internet and does not need to."
        hint "Compare docker-compose.yml with the template of your release; ./update.sh restores it."
    fi
elif [ "$TRAEFIK_ENABLE" = "true" ] && network_exists "$WEB_NETWORK"; then
    # An external Traefik: all that can be said is who else sits on that network.
    _others="$(docker network inspect -f '{{range .Containers}}{{.Name}} {{end}}' "$WEB_NETWORK" 2>/dev/null </dev/null)"
    info "Containers on $WEB_NETWORK: ${_others:-none}"
    case "$_others" in
        *traefik*) ok "A Traefik container sits on $WEB_NETWORK" ;;
        *)
            warn "No container named like Traefik on $WEB_NETWORK. Is this the network your proxy watches?"
            ;;
    esac
fi

# ------------------------------------------------------------ Application ----

section "Application"

if [ -z "$APP_ID" ] || [ "$(inspect "$APP_ID" '{{.State.Status}}')" != "running" ]; then
    fail "The app container is not running, application checks skipped."
    hint "docker compose logs --tail=100 app"
else
    if in_app wget -qO /dev/null http://localhost:8080/up >/dev/null; then
        ok "App answers on /up"
    else
        fail "App does not answer on /up inside its container."
        hint "docker compose logs --tail=100 app"
    fi

    # One tinker run for everything that needs the framework: each start costs a
    # second or two. Every probe prints key=value and survives its own failure.
    # shellcheck disable=SC2016 # PHP code, the dollar signs belong to PHP
    _probe="$(in_app php artisan tinker --execute '
        $probe = function (string $key, callable $check) {
            try { echo $key."=".$check()."\n"; }
            catch (Throwable $e) { echo $key."=ERROR ".str_replace(["\n", "\r"], " ", $e->getMessage())."\n"; }
        };
        $probe("db", fn () => Illuminate\Support\Facades\DB::connection()->getPdo() ? "ok" : "fail");
        $probe("redis", fn () => Illuminate\Support\Facades\Redis::connection()->ping() ? "ok" : "fail");
        $probe("queue_default", fn () => Illuminate\Support\Facades\Queue::size());
        $probe("queue_media", fn () => Illuminate\Support\Facades\Queue::connection("media")->size(config("queue.connections.media.queue", "media")));
        $probe("failed_jobs", fn () => Illuminate\Support\Facades\DB::table("failed_jobs")->count());
        $probe("stations", fn () => App\Models\Station::count());
        $probe("users", fn () => App\Models\User::count());
    ' </dev/null)"

    probe_value() { printf '%s\n' "$_probe" | sed -n "s/^$1=//p" | head -n 1; }

    _db="$(probe_value db)"
    case "$_db" in
        ok) ok "Database connection works" ;;
        '')
            fail "Could not run the framework inside the app container."
            printf '%s\n' "$_probe" | tail -n 3 | while IFS= read -r _l; do info "$_l"; done
            ;;
        *)
            fail "Database: $_db"
            hint "Check DB_HOST, DB_USERNAME and DB_PASSWORD in .env. Bundled: docker compose logs mysql"
            ;;
    esac

    _redis="$(probe_value redis)"
    case "$_redis" in
        ok) ok "Redis connection works" ;;
        '') ;;
        *)
            fail "Redis: $_redis"
            hint "Sessions, cache, queue and skip commands all need Redis. Check REDIS_HOST and REDIS_PASSWORD."
            ;;
    esac

    if [ -n "$_db" ]; then
        _pending="$(in_app php artisan migrate:status 2>/dev/null </dev/null | grep -c 'Pending' || true)"
        if [ "${_pending:-0}" -gt 0 ] 2>/dev/null; then
            fail "$_pending database migrations are pending."
            hint "They run on every app start: docker compose restart app, then docker compose logs app"
        else
            ok "Database schema is up to date"
        fi
    fi

    for _pair in "queue_default:default" "queue_media:media"; do
        _v="$(probe_value "${_pair%%:*}")"
        case "$_v" in
            ''|ERROR*) ;;
            *)
                if [ "$_v" -gt 50 ] 2>/dev/null; then
                    warn "Queue ${_pair#*:}: $_v jobs waiting. Is its worker stuck?"
                else
                    ok "Queue ${_pair#*:}: $_v jobs waiting"
                fi
                ;;
        esac
    done

    _failed="$(probe_value failed_jobs)"
    case "$_failed" in
        ''|ERROR*|0) ;;
        *)
            warn "$_failed failed jobs"
            hint "docker compose exec app php artisan queue:failed"
            ;;
    esac

    _stations="$(probe_value stations)"
    _users="$(probe_value users)"
    case "$_stations" in ''|ERROR*) ;; *) info "Stations:    $_stations, users: ${_users:-?}" ;; esac

    # Without the workers no rundown is generated and no station container
    # starts; without the scheduler nothing is planned ahead. The patterns match
    # docker/entrypoint.sh and docker/healthcheck.sh.
    if [ "$APP_MODE" = "all" ]; then
        _procs="$(in_app ps -o args 2>/dev/null || in_app ps 2>/dev/null)"
        for _pair in "artisan queue:work --sleep:Queue worker (default)" \
                     "artisan queue:work media:Queue worker (media)" \
                     "artisan schedule:work:Scheduler"; do
            case "$_procs" in
                *"${_pair%%:*}"*) ok "${_pair#*:} is running" ;;
                *)
                    fail "${_pair#*:} is not running"
                    hint "docker compose restart app"
                    ;;
            esac
        done
    else
        info "APP_MODE=$APP_MODE: workers and scheduler run elsewhere, not checked here."
    fi

    # The app starts and stops the station containers through this API.
    case "$CONTAINER_DRIVER" in
        docker)
            _docker_host="$(env_get DOCKER_HOST)"
            case "$_docker_host" in
                tcp://*)
                    _url="http://${_docker_host#tcp://}/version"
                    if in_app wget -qO /dev/null "$_url" >/dev/null; then
                        ok "App reaches the Docker API ($_docker_host)"
                    else
                        fail "App cannot reach the Docker API at $_docker_host. Stations cannot be started."
                        hint "Is the dockerproxy service running? COMPOSE_PROFILES needs driver-docker."
                    fi
                    ;;
                *) info "DOCKER_HOST=$_docker_host, not checked." ;;
            esac
            ;;
        portainer)
            _endpoint="$(env_get PORTAINER_ENDPOINT)"
            if [ -n "$_endpoint" ] && in_app wget -qO /dev/null "${_endpoint%/}/status" >/dev/null; then
                ok "App reaches Portainer ($_endpoint)"
            else
                fail "App cannot reach Portainer at ${_endpoint:-?}"
            fi
            ;;
        *)
            warn "Unknown CONTAINER_DRIVER '$CONTAINER_DRIVER'. Stations cannot be started."
            ;;
    esac

    # LOG_CHANNEL=stderr, so errors land in the container log.
    _errors="$(dc logs --since 60m app 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)"
    if [ -n "$_errors" ]; then
        _count="$(printf '%s\n' "$_errors" | wc -l | tr -d ' ')"
        warn "$_count errors in the app log during the last hour. The newest:"
        printf '%s\n' "$_errors" | tail -n 3 | cut -c1-200 | redact | while IFS= read -r _l; do info "$_l"; done
        hint "docker compose logs --since 60m app"
    else
        ok "No errors in the app log during the last hour"
    fi
fi

# --------------------------------------------------------------- Stations ----

section "Station containers"

_rows="$(docker ps -a --filter "label=managed_by=$MANAGED_BY" \
    --format '{{.Names}}|{{.State}}|{{.Image}}' 2>/dev/null </dev/null)"

if [ -z "$_rows" ]; then
    info "No station containers on this host."
    if [ "$CONTAINER_DRIVER" = "portainer" ]; then
        info "With Portainer they may run on another host."
    else
        info "Normal until a station is started in the panel."
    fi
else
    _outdated=0
    _pulled=1
    [ -n "$STATION_IMAGE" ] && ! docker image inspect "$STATION_IMAGE" >/dev/null 2>&1 </dev/null && _pulled=0

    printf '%s\n' "$_rows" | sort > "${TMPDIR:-/tmp}/rr-support.$$"
    while IFS='|' read -r _name _state _image; do
        case "$_name" in
            radioring-icecast-*) _kind="Icecast"; _want_net="$DOCKER_WEB_NETWORK"; _want_image="$ICECAST_IMAGE" ;;
            *) _kind="Station"; _want_net="$STATION_NETWORK"; _want_image="$STATION_IMAGE" ;;
        esac

        case "$_state" in
            running) ok "$_kind $_name is running" ;;
            restarting)
                fail "$_kind $_name keeps restarting"
                hint "docker logs --tail=100 $_name"
                ;;
            *)
                warn "$_kind $_name is $_state (exit code $(inspect "$_name" '{{.State.ExitCode}}'))"
                hint "Start it again in the panel, or: docker logs --tail=100 $_name"
                ;;
        esac

        if ! on_network "$_name" "$_want_net"; then
            fail "$_name is not on the network $_want_net."
            if [ "$_kind" = "Station" ]; then
                hint "It cannot reach the app. Stop and start the station in the panel after fixing DOCKER_STATION_NETWORK."
            else
                hint "Traefik cannot route to it. Check DOCKER_WEB_NETWORK."
            fi
        fi

        # Station and sidecar meet in the stream network. Containers from before it
        # existed pick it up when the station is stopped and started again.
        if [ -n "$STREAM_NETWORK" ] && ! on_network "$_name" "$STREAM_NETWORK"; then
            warn "$_name is not on the stream network $STREAM_NETWORK."
            hint "Stop and start the station in the panel, it is then recreated in the current network layout."
        fi

        # The sidecar is public. In the internal network it would reach the database.
        if [ "$_kind" = "Icecast" ] && on_network "$_name" "$STATION_NETWORK"; then
            fail "$_name sits in the internal network $STATION_NETWORK, next to database and Redis."
            hint "Stop and start the station in the panel, it is then recreated in the current network layout."
        fi

        if [ -n "$_want_image" ] && [ "$_image" != "$_want_image" ]; then
            _outdated=$((_outdated + 1))
            info "$_name runs $_image"
        fi

        report_block "docker logs --tail=40 $_name" docker logs --tail=40 "$_name"
    done < "${TMPDIR:-/tmp}/rr-support.$$"
    rm -f "${TMPDIR:-/tmp}/rr-support.$$"

    if [ "$_outdated" -gt 0 ]; then
        warn "$_outdated containers still run an older image than the .env names."
        hint "Normal right after an update: restart the stations in the panel to move them over."
    fi
    if [ "$_pulled" = "0" ]; then
        info "$STATION_IMAGE is not pulled yet. The next station start pulls it."
    fi
fi

# ------------------------------------------------------------ Service logs ----

section "Service logs (last 24 hours)"

# A container can be up and healthy and still fail at its job: a socket proxy
# refusing every call, a Traefik without a certificate, a station that cannot
# reach the app. Those only show in the logs, so they are searched for known
# patterns. The app log was already checked above.

LOG_WINDOW="--since 24h --tail 2000"

# check_log_rule NAME LOGS REGEX WHAT HINT [EXCLUDE]
#
# Warns with the number of matching lines, the newest two and a hint. Returns 0
# when something matched, so that the caller knows whether the log was clean.
check_log_rule() {
    _matches="$(printf '%s\n' "$2" | grep -E -- "$3" || true)"
    if [ -n "${6:-}" ] && [ -n "$_matches" ]; then
        _matches="$(printf '%s\n' "$_matches" | grep -Ev -- "$6" || true)"
    fi
    [ -n "$_matches" ] || return 1

    _count="$(printf '%s\n' "$_matches" | wc -l | tr -d ' ')"
    warn "$1: $4 (matching lines: $_count)"
    printf '%s\n' "$_matches" | tail -n 2 | cut -c1-200 | redact \
        | while IFS= read -r _l; do info "$_l"; done
    hint "$5"
    return 0
}

# check_service_logs NAME LOGS KIND
check_service_logs() {
    _name="$1"; _logs="$2"; _hits=0

    case "$3" in
        dockerproxy)
            # HAProxy log format: "... backend/<NOSRV> 0/-1/-1/-1/0 403 ...".
            check_log_rule "$_name" "$_logs" '<NOSRV>| 403 [0-9]+ ' \
                "Docker API calls refused by the socket proxy" \
                "The app asked for an endpoint the proxy does not allow. Compare the dockerproxy environment with the template of your release; ./update.sh restores it." \
                && _hits=1
            check_log_rule "$_name" "$_logs" 'docker\.sock|[Pp]ermission denied|connect\(\) failed' \
                "the proxy cannot reach the Docker socket" \
                "Is /var/run/docker.sock present on the host? Rootless Docker keeps it elsewhere." \
                && _hits=1
            ;;
        dockerproxy-traefik)
            check_log_rule "$_name" "$_logs" '<NOSRV>| 403 [0-9]+ ' \
                "Docker API calls from Traefik refused" \
                "Traefik then sees no containers. The template grants CONTAINERS, NETWORKS, SERVICES and TASKS; ./update.sh restores it." \
                && _hits=1
            ;;
        traefik)
            check_log_rule "$_name" "$_logs" '( ERR | FTL |level=(error|fatal)).*(acme|ACME|certificate)' \
                "certificate errors" \
                "Let's Encrypt needs the A record of the domain and port 443 reachable from the internet. Rate limits clear after an hour." \
                && _hits=1
            check_log_rule "$_name" "$_logs" ' ERR | FTL |level=(error|fatal)' \
                "errors" \
                "docker compose logs --since 24h traefik" \
                'acme|ACME|certificate' \
                && _hits=1
            ;;
        mysql)
            check_log_rule "$_name" "$_logs" 'Too many connections|[Oo]ut of memory|No space left' \
                "the database is running out of resources" \
                "Check free memory and disk space above." \
                && _hits=1
            check_log_rule "$_name" "$_logs" '\[ERROR\]' \
                "errors" \
                "docker compose logs --since 24h mysql" \
                'Too many connections|[Oo]ut of memory|No space left' \
                && _hits=1
            ;;
        redis)
            check_log_rule "$_name" "$_logs" 'OOM|MISCONF|Can.t save|Background saving error|No space left' \
                "Redis cannot write or ran out of memory" \
                "With MISCONF Redis refuses writes: sessions, queue and skip commands stop. Check disk space." \
                && _hits=1
            check_log_rule "$_name" "$_logs" 'overcommit_memory' \
                "vm.overcommit_memory is off" \
                "Background saves may fail under memory pressure: sysctl vm.overcommit_memory=1" \
                && _hits=1
            ;;
        station)
            # Liquidsoap levels: 1 critical, 2 severe. 3 and above are routine.
            check_log_rule "$_name" "$_logs" 'Connection refused|[Cc]ould not resolve|[Nn]ame or service not known' \
                "cannot reach the app or an output" \
                "The station has to share a network with the app (DOCKER_STATION_NETWORK). External outputs: check host and port in the panel." \
                && _hits=1
            check_log_rule "$_name" "$_logs" ' 401 |Unauthorized|[Aa]uthentication failed' \
                "requests rejected as unauthorised" \
                "Station token or output password no longer matches. Restart the station in the panel so it picks up the current credentials." \
                && _hits=1
            check_log_rule "$_name" "$_logs" '\[[A-Za-z0-9_.-]+:[12]\]' \
                "severe errors from Liquidsoap" \
                "docker logs --since 24h $_name" \
                'Connection refused|[Cc]ould not resolve|[Nn]ame or service not known| 401 |Unauthorized|[Aa]uthentication failed' \
                && _hits=1
            ;;
        icecast)
            check_log_rule "$_name" "$_logs" 'EROR' \
                "Icecast errors" \
                "docker logs --since 24h $_name" \
                && _hits=1
            ;;
    esac

    return "$_hits"
}

_clean=""
for _svc in $EXPECTED_SERVICES; do
    [ "$_svc" = "app" ] && continue
    # shellcheck disable=SC2086 # LOG_WINDOW is meant to split into flags
    _logs="$(dc logs $LOG_WINDOW --no-color "$_svc" 2>&1)"
    if check_service_logs "$_svc" "$_logs" "$_svc"; then
        _clean="$_clean $_svc"
    fi
done

_station_clean=0
if [ -n "$_rows" ]; then
    while IFS='|' read -r _name _state _image; do
        [ -n "$_name" ] || continue
        case "$_name" in
            radioring-icecast-*) _kind=icecast ;;
            *) _kind=station ;;
        esac
        # shellcheck disable=SC2086 # LOG_WINDOW is meant to split into flags
        _logs="$(docker logs $LOG_WINDOW "$_name" 2>&1 </dev/null)"
        if check_service_logs "$_name" "$_logs" "$_kind"; then
            _station_clean=$((_station_clean + 1))
        fi
    done <<ROWS
$_rows
ROWS
fi

[ -n "$_clean" ] && ok "Nothing suspicious in the logs of:$_clean"
[ "$_station_clean" -gt 0 ] && ok "Nothing suspicious in the logs of $_station_clean station and Icecast containers"

# -------------------------------------------------- Reachability from here ----

section "Reachability"

# Loopback first: it shows whether the app is up regardless of DNS and proxy.
if have curl; then
    _code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://$APP_BIND:$APP_PORT/up" 2>/dev/null || true)"
    if [ "$_code" = "200" ]; then
        ok "App answers on http://$APP_BIND:$APP_PORT/up"
    else
        warn "No answer on http://$APP_BIND:$APP_PORT/up (HTTP ${_code:-none})"
    fi
fi

# Ports the bundled Traefik publishes. Anything else listening there means the
# proxy could not start or does not get the traffic.
if list_has "$COMPOSE_PROFILES" traefik && have ss; then
    for _port in 80 443; do
        if ss -ltnH 2>/dev/null | awk '{ print $4 }' | grep -Eq "[:.]$_port\$"; then
            ok "Port $_port is listening"
        else
            fail "Nothing listens on port $_port. Let's Encrypt and the panel need it."
            hint "docker compose logs traefik"
        fi
    done
fi

resolve() {
    if have getent; then
        getent ahostsv4 "$1" 2>/dev/null | awk '{ print $1 }' | sort -u | tr '\n' ' '
    elif have nslookup; then
        nslookup "$1" 2>/dev/null | awk '/^Address/ && !/#/ { print $2 }' | tr '\n' ' '
    fi
}

if [ "$OFFLINE" = "1" ]; then
    info "DNS and HTTPS checks skipped (--offline)."
elif [ -n "$APP_HOST" ] && [ "$APP_HOST" != "panel.example.com" ]; then
    _local_ips="$(hostname -I 2>/dev/null || ip -o -4 addr show 2>/dev/null | awk '{ print $4 }' | cut -d/ -f1 | tr '\n' ' ')"
    info "This host:   ${_local_ips:-?}"

    _ips="$(resolve "$APP_HOST")"
    if [ -z "$_ips" ]; then
        fail "$APP_HOST does not resolve."
        hint "Create an A record: $APP_HOST -> IP of this server"
    else
        ok "$APP_HOST resolves to $_ips"
        _match=0
        for _ip in $_ips; do
            case " $_local_ips " in *" $_ip "*) _match=1 ;; esac
        done
        [ "$_match" = "1" ] || info "No local address matches. Fine behind NAT or a load balancer, otherwise check the A record."
    fi

    if [ -n "$STREAM_DOMAIN" ] && [ "$STREAM_DOMAIN" != "stream.example.com" ]; then
        _ips="$(resolve "rr-support-check.$STREAM_DOMAIN")"
        if [ -n "$_ips" ]; then
            ok "*.$STREAM_DOMAIN resolves to $_ips"
        else
            warn "*.$STREAM_DOMAIN does not resolve. Live input and internal Icecast need the wildcard record."
            hint "A  *.$STREAM_DOMAIN -> IP of this server"
        fi
        info "Live input ports $STREAM_PORT_MIN-$STREAM_PORT_MAX/tcp must be open in the firewall (cannot be tested from here)."
    elif [ "$STREAM_DOMAIN" = "stream.example.com" ]; then
        warn "STREAM_DOMAIN is still the placeholder stream.example.com."
    fi

    if [ "$TRAEFIK_ENABLE" = "true" ] && have curl; then
        _code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "https://$APP_HOST/up" 2>/dev/null)"
        _rc=$?
        if [ "$_code" = "200" ]; then
            ok "https://$APP_HOST answers with a valid certificate"
        elif [ "$_rc" = "60" ] || [ "$_rc" = "35" ]; then
            fail "https://$APP_HOST has no valid certificate yet."
            hint "Let's Encrypt needs the A record and port 443 reachable from the internet."
            if [ -n "$TRAEFIK_ID" ]; then
                dc logs --tail=300 traefik 2>&1 | grep -i 'acme' | tail -n 2 | cut -c1-200 \
                    | while IFS= read -r _l; do info "$_l"; done
            fi
        else
            warn "https://$APP_HOST/up answered HTTP ${_code:-nothing} (curl exit $_rc)."
            hint "Some hosts cannot reach their own public address (no hairpin NAT). Try from another machine."
        fi
    fi
fi

# ------------------------------------------------------------------ Report ----

if [ -n "$REPORT" ]; then
    to_report ""
    to_report "=================================== Details ==================================="
    report_block ".env (secrets masked)" \
        sed -E 's/^([A-Z0-9_]*(PASSWORD|SECRET|TOKEN|_KEY)=).+/\1***/' .env
    report_block "docker compose ps" docker compose ps -a
    report_block "docker network ls" docker network ls
    report_block "docker ps (all)" docker ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'
    report_block "docker system df" docker system df
    for _svc in $EXPECTED_SERVICES; do
        report_block "docker compose logs --tail=80 $_svc" docker compose logs --tail=80 --no-color "$_svc"
    done
fi

# ----------------------------------------------------------------- Summary ----

summary_and_exit
