#!/bin/sh
# RadioRing updater. Sits next to the docker-compose.yml of an installation.
#
#   ./update.sh                  Move to the newest version in the channel
#   ./update.sh --check          Show what would change, change nothing
#   ./update.sh --version=v1.2.0 Move to this exact release (up or down)
#   ./update.sh --channel=NAME   Switch to stable or edge and move there
#   ./update.sh --keep-compose   Leave the local docker-compose.yml untouched
#   ./update.sh --force          Do not stop at a major version jump
#
# Updating means moving three things together: the app image, the station image
# and the compose template. They are pinned to one git tag in the .env, and this
# script is the only thing that rewrites those pins. Editing RADIORING_IMAGE by
# hand leaves RR_VERSION behind, and the next run of this script would happily
# "update" back to a template from a different release.

set -eu

cd "$(dirname "$0")"

[ -f docker-compose.yml ] || { echo "No docker-compose.yml in $(pwd)." >&2; exit 1; }
[ -f .env ] || { echo "No .env in $(pwd)." >&2; exit 1; }

KEEP_COMPOSE=0
CHECK_ONLY=0
FORCE=0
WANTED_VERSION=""
WANTED_CHANNEL=""
for _arg in "$@"; do
    case "$_arg" in
        --check) CHECK_ONLY=1 ;;
        --keep-compose) KEEP_COMPOSE=1 ;;
        --force) FORCE=1 ;;
        --version=*) WANTED_VERSION="${_arg#--version=}" ;;
        --channel=*) WANTED_CHANNEL="${_arg#--channel=}" ;;
        *) echo "Unknown option: $_arg" >&2; exit 1 ;;
    esac
done

# Read a single value out of the .env without sourcing it. Sourcing would pull
# every database password into this shell for no reason, and would execute
# whatever a mangled line happens to contain.
env_get() {
    grep -E "^$1=" .env 2>/dev/null | head -n 1 | cut -d= -f2- || true
}

CHANNEL="${WANTED_CHANNEL:-$(env_get RR_CHANNEL)}"
[ -n "$CHANNEL" ] || CHANNEL=stable

case "$CHANNEL" in
    stable|edge) ;;
    *) echo "Unknown channel: $CHANNEL (expected stable or edge)" >&2; exit 1 ;;
esac

CURRENT_VERSION="$(env_get RR_VERSION)"
[ -n "$CURRENT_VERSION" ] || CURRENT_VERSION="main"

REPO_SLUG="$(env_get RR_REPO_SLUG)"
[ -n "$REPO_SLUG" ] || REPO_SLUG="radioring/radioring"

IMAGE_OWNER="${REPO_SLUG%%/*}"

latest_release() {
    curl -fsSL "https://api.github.com/repos/$REPO_SLUG/releases/latest" 2>/dev/null \
        | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
        | head -n 1
}

# ------------------------------------------------------------- Target ----

if [ -n "$WANTED_VERSION" ]; then
    TARGET_VERSION="$WANTED_VERSION"
    CHANNEL=stable
elif [ "$CHANNEL" = "edge" ]; then
    TARGET_VERSION="main"
else
    TARGET_VERSION="$(latest_release || true)"

    if [ -z "$TARGET_VERSION" ]; then
        echo "Could not determine the newest release of $REPO_SLUG." >&2
        echo "Network problem, or the project has not published one yet." >&2
        exit 1
    fi
fi

if [ "$CHANNEL" = "edge" ]; then
    TARGET_IMAGE_TAG="edge"
else
    TARGET_IMAGE_TAG="${TARGET_VERSION#v}"
fi

REPO_RAW="https://raw.githubusercontent.com/$REPO_SLUG/$TARGET_VERSION"

echo "Channel:  $CHANNEL"
echo "Current:  $CURRENT_VERSION"
echo "Target:   $TARGET_VERSION"

# A jump across a major version is the one case where reading the release notes
# is not optional, so it stops here unless the operator insists. Comparing only
# the leading number is crude but it never reports a false major.
major_of() { printf '%s' "${1#v}" | cut -d. -f1; }

if [ "$CHANNEL" = "stable" ] && [ "$CURRENT_VERSION" != "main" ]; then
    _from="$(major_of "$CURRENT_VERSION")"
    _to="$(major_of "$TARGET_VERSION")"

    if [ "$_from" != "$_to" ] && [ "$FORCE" = "0" ] && [ "$CHECK_ONLY" = "0" ]; then
        echo
        echo "This is a major version jump: $CURRENT_VERSION -> $TARGET_VERSION." >&2
        echo "Read the release notes first:" >&2
        echo "  https://github.com/$REPO_SLUG/releases/tag/$TARGET_VERSION" >&2
        echo "Then run: ./update.sh --force" >&2
        exit 1
    fi
fi

if [ "$CHECK_ONLY" = "1" ]; then
    echo
    if [ "$CURRENT_VERSION" = "$TARGET_VERSION" ] && [ "$CHANNEL" != "edge" ]; then
        echo "Already on the newest release."
    else
        echo "Would move to $TARGET_VERSION (image tag $TARGET_IMAGE_TAG)."
    fi
    echo
    echo "Comparing local images against the registry ..."
    docker compose pull --dry-run 2>/dev/null </dev/null || docker compose pull </dev/null
    exit 0
fi

echo
echo "Back up the database before updating. Enter to continue, Ctrl-C to abort."
if [ -t 0 ]; then
    read -r _ignored || true
fi

# ------------------------------------------------------------ Rewrite ----

# Never write straight into a file that is being read: download beside it and
# move it into place, so a half-finished transfer cannot leave a broken file.
fetch() {
    _url="$1"; _dest="$2"; _tmp="$2.download.$$"

    if curl -fsSL "$_url" -o "$_tmp" 2>/dev/null; then
        mv -f "$_tmp" "$_dest"
        return 0
    fi

    rm -f "$_tmp"
    return 1
}

# Rewrite one KEY=value line in the .env, or append it when it is missing.
env_set() {
    _key="$1"; _value="$2"; _tmp=".env.update.$$"

    if grep -qE "^$_key=" .env; then
        # The value can hold slashes and ampersands, so sed's replacement side
        # is out. awk with the value in a variable never reinterprets it.
        awk -v key="$_key" -v value="$_value" \
            'index($0, key "=") == 1 { print key "=" value; next } { print }' \
            .env > "$_tmp"
    else
        cp .env "$_tmp"
        printf '%s=%s\n' "$_key" "$_value" >> "$_tmp"
    fi

    # Set explicitly rather than copied from the original with --reference:
    # that flag is a GNU extension, and a silent no-op would leave the database
    # and Redis passwords world readable.
    chmod 600 "$_tmp"
    mv -f "$_tmp" .env
}

cp .env ".env.bak.$(date +%s)"

env_set RR_CHANNEL "$CHANNEL"
env_set RR_VERSION "$TARGET_VERSION"
env_set RR_REPO_SLUG "$REPO_SLUG"
env_set RR_REPO_RAW "$REPO_RAW"
env_set RADIORING_IMAGE "ghcr.io/$IMAGE_OWNER/radioring:$TARGET_IMAGE_TAG"
env_set STATION_IMAGE "ghcr.io/$IMAGE_OWNER/liquidsoap-station:$TARGET_IMAGE_TAG"
echo ".env pinned to $TARGET_VERSION"

# The compose template belongs to the release, not to the installation day: it
# pins Traefik, MySQL and Redis and defines the service topology. Pulling new
# app images against last year's template is how a "working" update produces a
# stack nobody has ever tested.
#
# This is safe because install.sh stores the template unchanged. Everything
# installation specific lives in the .env, none of it in that file.
if [ "$KEEP_COMPOSE" = "1" ]; then
    echo "docker-compose.yml left untouched (--keep-compose)."
elif fetch "$REPO_RAW/docker/templates/docker-compose.yml" docker-compose.yml.new; then
    if cmp -s docker-compose.yml docker-compose.yml.new; then
        rm -f docker-compose.yml.new
    else
        cp docker-compose.yml docker-compose.yml.bak
        mv docker-compose.yml.new docker-compose.yml
        echo "docker-compose.yml updated. Previous version: docker-compose.yml.bak"
        echo "Local edits to that file are overwritten; 'diff docker-compose.yml.bak docker-compose.yml' shows them."
    fi
else
    echo "Warning: could not download docker-compose.yml from $REPO_RAW, keeping the local one." >&2
fi

# Pull the matching updater along, otherwise an installation keeps updating with
# the logic of the release it was installed from.
fetch "$REPO_RAW/update.sh" update.sh.new && {
    chmod +x update.sh.new 2>/dev/null || true
    mv -f update.sh.new update.sh
}

docker compose pull </dev/null
docker compose up -d </dev/null

# Migrations run in the container entrypoint. They only have to be triggered
# here when the app is split across containers (APP_MODE != all).
if [ "$(env_get APP_MODE)" != "all" ]; then
    docker compose exec -T app php artisan migrate --force </dev/null
fi

docker image prune -f >/dev/null 2>&1 || true

echo "Update finished. Now on $TARGET_VERSION."
