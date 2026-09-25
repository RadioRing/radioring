#!/bin/bash
set -euo pipefail

# ===================== Konfig (ENV-Overrides möglich) =====================
: "${LS_WORKDIR:=/app/liquidsoap}"
: "${LS_SCRIPT:=streamer.liq}"
: "${SCRIPT_REFRESH:=true}"   # Script bei jedem Start frisch von der API holen

# ===================== Pflicht-ENV prüfen =====================
need_vars=(API_URL SLUG TOKEN)
missing=()
for v in "${need_vars[@]}"; do [[ -z "${!v:-}" ]] && missing+=("$v"); done
if (( ${#missing[@]} )); then
  echo "Error: Missing required env vars: ${missing[*]}"
  tail -f /dev/null
fi

SCRIPT_PATH="${LS_WORKDIR}/${LS_SCRIPT}"

# ===================== Script von RadioRing holen =====================
fetch_script() {
  echo "Fetching Liquidsoap script from ${API_URL}/api/liquidsoap/${SLUG}/script ..."
  local http_code
  http_code=$(curl -s -o "${SCRIPT_PATH}.tmp" -w "%{http_code}" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${API_URL}/api/liquidsoap/${SLUG}/script")

  if [[ "$http_code" != "200" || ! -s "${SCRIPT_PATH}.tmp" ]]; then
    echo "Error: Could not fetch script (http=${http_code})."
    rm -f "${SCRIPT_PATH}.tmp"
    return 1
  fi

  mv "${SCRIPT_PATH}.tmp" "${SCRIPT_PATH}"
  echo "Script written to ${SCRIPT_PATH}."
}

# ===================== Fetch the Stereo Tool preset =====================
# Must match config('radioring.stereo_tool.active_preset_file').
: "${STEREO_TOOL_PRESET_PATH:=${LS_WORKDIR}/stereotool-preset.sts}"

fetch_stereo_tool_preset() {
  local http_code
  http_code=$(curl -s -o "${STEREO_TOOL_PRESET_PATH}.tmp" -w "%{http_code}" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${API_URL}/api/liquidsoap/${SLUG}/stereo-tool/preset")

  if [[ "$http_code" == "200" && -s "${STEREO_TOOL_PRESET_PATH}.tmp" ]]; then
    mv "${STEREO_TOOL_PRESET_PATH}.tmp" "${STEREO_TOOL_PRESET_PATH}"
    echo "Stereo Tool preset written to ${STEREO_TOOL_PRESET_PATH}."
    return 0
  fi

  rm -f "${STEREO_TOOL_PRESET_PATH}.tmp"

  if [[ "$http_code" == "404" ]]; then
    # Nothing selected: the old file has to go, or the station keeps running the preset it
    # was just unassigned from.
    rm -f "${STEREO_TOOL_PRESET_PATH}"
    echo "No Stereo Tool preset selected (http=404)."
  else
    # Unreachable API: keep the existing file, so an outage does not change how it sounds.
    echo "Stereo Tool preset not fetched (http=${http_code}), keeping the existing file."
  fi

  return 0
}

# ===================== Sync the emergency loop =====================
# Must match config('radioring.emergency.directory'). The files play from here while the
# programme branch is unavailable, so they stay put when the API cannot be reached.
: "${EMERGENCY_DIR:=${LS_WORKDIR}/emergency}"
: "${EMERGENCY_SYNC_INTERVAL:=900}"

EMERGENCY_PLAYLIST="${EMERGENCY_DIR}/emergency.m3u"

# Liquidsoap evaluates playlist() at startup, so the file has to exist before the first
# start, even empty.
init_emergency() {
  mkdir -p "${EMERGENCY_DIR}"
  [[ -f "$EMERGENCY_PLAYLIST" ]] || echo "# no emergency files" > "$EMERGENCY_PLAYLIST"
}

# Manifest fields per line: name, url, gain. A file is named {media_id}-{updated_at}, so a
# name already present is up to date and a replaced file arrives under a new name.
emergency_manifest_rows() {
  jq -r '.files[] | [.name, .url, (.amplify // "")] | @tsv' "$1"
}

sync_emergency() {
  local manifest http_code name url amplify base wanted=""
  manifest=$(mktemp)

  http_code=$(curl -s -o "$manifest" -w "%{http_code}" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${API_URL}/api/liquidsoap/${SLUG}/emergency")

  # Unreachable API: keep what is there. This is the moment the loop is needed.
  if [[ "$http_code" != "200" ]]; then
    echo "Emergency loop not synced (http=${http_code}), keeping the existing files."
    rm -f "$manifest"
    return 0
  fi

  init_emergency

  while IFS=$'\t' read -r name url amplify; do
    [[ -n "$name" ]] || continue
    wanted+="${name}"$'\n'

    [[ -s "${EMERGENCY_DIR}/${name}" ]] && continue

    echo "Emergency loop: downloading ${name} ..."
    if curl -sf -o "${EMERGENCY_DIR}/${name}.tmp" "$url"; then
      mv "${EMERGENCY_DIR}/${name}.tmp" "${EMERGENCY_DIR}/${name}"
    else
      echo "Emergency loop: download of ${name} failed, skipped."
      rm -f "${EMERGENCY_DIR}/${name}.tmp"
    fi
  done < <(emergency_manifest_rows "$manifest")

  # Drop what is no longer selected, so an unselected file stops playing.
  for base in "${EMERGENCY_DIR}"/*; do
    [[ -f "$base" ]] || continue
    base="${base##*/}"
    [[ "$base" == "emergency.m3u" ]] && continue
    if ! printf '%s' "$wanted" | grep -qxF "$base"; then
      echo "Emergency loop: removing ${base}."
      rm -f "${EMERGENCY_DIR}/${base}"
    fi
  done

  # Written through .tmp: Liquidsoap re-reads the playlist after every round and must never
  # see a half written one. Each line carries the offline measured gain.
  : > "${EMERGENCY_PLAYLIST}.tmp"

  while IFS=$'\t' read -r name url amplify; do
    [[ -s "${EMERGENCY_DIR}/${name}" ]] || continue
    if [[ -n "$amplify" ]]; then
      echo "annotate:liq_amplify=\"${amplify}\":${EMERGENCY_DIR}/${name}" >> "${EMERGENCY_PLAYLIST}.tmp"
    else
      echo "${EMERGENCY_DIR}/${name}" >> "${EMERGENCY_PLAYLIST}.tmp"
    fi
  done < <(emergency_manifest_rows "$manifest")

  [[ -s "${EMERGENCY_PLAYLIST}.tmp" ]] || echo "# no emergency files" > "${EMERGENCY_PLAYLIST}.tmp"

  mv "${EMERGENCY_PLAYLIST}.tmp" "$EMERGENCY_PLAYLIST"
  rm -f "$manifest"
  echo "Emergency loop synced ($(grep -cv '^#' "$EMERGENCY_PLAYLIST" || true) file(s))."
}

# Safety net for a selection changed while the container runs, in case the sync_emergency
# command never reached it.
emergency_loop() {
  while true; do
    sleep "$EMERGENCY_SYNC_INTERVAL"
    sync_emergency || true
  done
}

if [[ "$SCRIPT_REFRESH" == "true" || ! -f "$SCRIPT_PATH" ]]; then
  for attempt in {1..5}; do
    if fetch_script; then break; fi
    echo "Attempt ${attempt}/5 failed. Retrying in 5s..."
    sleep 5
  done
fi

if [[ ! -s "$SCRIPT_PATH" ]]; then
  echo "Error: No Liquidsoap script available. Exiting to log tail."
  tail -f /dev/null
fi

# ===================== Pull-Cursor auf Airplay zurücksetzen =====================
# Liquidsoap verliert beim (Neu-)Start seinen prefetch-Puffer, der DB-Cursor ist
# diesem aber vorausgeeilt. Ohne diesen Reset würde der Stream nach dem Start
# mehrere Tracks überspringen. Muss VOR jedem Liquidsoap-Start passieren.
reset_cursor() {
  echo "Resetting pull cursor to airplay position ..."
  curl -s -X POST -H "Authorization: Bearer ${TOKEN}" \
    "${API_URL}/api/liquidsoap/${SLUG}/connect" >/dev/null \
    || echo "connect: API nicht erreichbar (ignoriert)"
}

# ===================== Liquidsoap-Supervisor =====================
# Liquidsoap kann trotz aller try/catch im Script noch durch einen "uncaught"
# Runtime-Error abstürzen (z. B. wenn die Auflösung einer http-Request-URL in
# protocols.liq mit CURLE_RECV_ERROR fehlschlägt, oder ein Decoder crasht).
# Früher kehrte dann `wait $LS_PID` zurück, das Script endete und der Container
# lief tot weiter. Stattdessen überwachen wir den Prozess und starten ihn neu.
LS_PIDFILE=/tmp/liquidsoap.pid
SUPERVISOR_STOP=/tmp/liquidsoap.stop   # "restart"-Befehl: einmaliger Neustart, kein Stop
: "${LS_RESTART_DELAY:=3}"             # Backoff zwischen Neustarts (Sekunden)

cd "$LS_WORKDIR" || exit 1

supervise_liquidsoap() {
  local first_start=true

  while true; do
    # On EVERY restart, not only on container start: otherwise the "restart" command would
    # keep running the old script. A single attempt on purpose, so a crash restart does not
    # wait 25s on an unreachable API; fetch_script writes through .tmp and a failure leaves
    # the existing script alone.
    if [[ "$first_start" != "true" && "$SCRIPT_REFRESH" == "true" ]]; then
      fetch_script || echo "Script refresh failed, using the existing script."
    fi
    first_start=false

    # Before the first start too: the script references the path, so the file has to exist
    # when Liquidsoap evaluates the stereotool operator.
    fetch_stereo_tool_preset

    init_emergency
    sync_emergency || true

    reset_cursor

    echo "Starting Liquidsoap with ${SCRIPT_PATH} ..."
    liquidsoap "$LS_SCRIPT" &
    LS_PID=$!
    echo "$LS_PID" > "$LS_PIDFILE"

    # `wait` auf einen Hintergrundjob; bei Signal (kill durch Relay) ebenfalls Rückkehr.
    # `|| code=$?` verhindert, dass `set -e` bei nicht-null Exit den Supervisor killt.
    local code=0
    wait "$LS_PID" || code=$?
    echo "Liquidsoap beendet (exit=${code})."

    if [[ -f "$SUPERVISOR_STOP" ]]; then
      echo "Supervisor: Stop angefordert – kein Neustart."
      rm -f "$SUPERVISOR_STOP"
      break
    fi

    echo "Supervisor: Neustart in ${LS_RESTART_DELAY}s ..."
    sleep "$LS_RESTART_DELAY"
  done
}

# ===================== Redis-Command-Relay =====================
# Subscribt auf CONTROL_CHANNEL und leitet Befehle per Telnet (127.0.0.1:1234)
# an Liquidsoap weiter. Nur Nachrichten für DIESEN Container (CONTAINER_NAME).
control_loop() {
  local auth=""
  if [[ -n "${REDIS_PASSWORD:-}" && "${REDIS_PASSWORD}" != "null" ]]; then
    auth="-a ${REDIS_PASSWORD} --no-auth-warning"
  fi

  echo "Subscribing to Redis control channel '${CONTROL_CHANNEL}' on ${REDIS_HOST}:${REDIS_PORT:-6379} (my container='${CONTAINER_NAME}') ..."
  # shellcheck disable=SC2086
  redis-cli $auth -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" -n "${REDIS_DB:-0}" --raw \
    subscribe "${CONTROL_CHANNEL}" | \
  while true; do
    IFS= read -r kind    || break
    IFS= read -r _channel|| break
    IFS= read -r payload || break

    [[ "$kind" == "message" ]] || continue

    cmd=$(printf '%s' "$payload" | jq -r '.command // empty' 2>/dev/null)
    tgt=$(printf '%s' "$payload" | jq -r '.container_name // empty' 2>/dev/null)

    # Vorlauf in Sekunden bis zum Schnitt (Hard-Start-Ankuendigung). Liquidsoap plant den
    # Cut damit selbst und blendet so aus, dass er auf der vollen Stunde liegt.
    # Bewusst streng geprueft: der Wert landet direkt in der Telnet-Befehlszeile.
    lead=$(printf '%s' "$payload" | jq -r '.lead // 0' 2>/dev/null)
    [[ "$lead" =~ ^[0-9]+(\.[0-9]+)?$ ]] || lead=0

    echo "Relay: Nachricht empfangen (cmd='${cmd}' target='${tgt}', erwartet='${CONTAINER_NAME:-}')"

    if [[ "$tgt" != "${CONTAINER_NAME:-}" ]]; then
      echo "Relay: target passt nicht – ignoriert."
      continue
    fi
    echo "Control command '${cmd}' for ${tgt}"

    case "$cmd" in
      skip)
        echo "skip: Schnitt in ${lead}s"
        printf 'radioring.flush_and_skip %s\nquit\n' "$lead" | nc -w 1 127.0.0.1 1234 || echo "skip: telnet nicht erreichbar"
        ;;
      sync_emergency)
        sync_emergency || echo "sync_emergency: failed"
        ;;
      restart)
        echo "restart → beende Liquidsoap (Supervisor startet es neu)"
        pid=$(cat "$LS_PIDFILE" 2>/dev/null || true)
        [[ -n "$pid" ]] && kill "$pid" 2>/dev/null || true
        ;;
      stop)
        # Liquidsoap kennt kein sauberes Output-Stop per Telnet → best effort skip.
        printf 'radioring.flush_and_skip 0\nquit\n' | nc -w 1 127.0.0.1 1234 || true
        ;;
      *)
        echo "Unbekannter Befehl: ${cmd}"
        ;;
    esac
  done
}

emergency_loop &

if [[ -n "${REDIS_HOST:-}" && -n "${CONTROL_CHANNEL:-}" && -n "${CONTAINER_NAME:-}" ]]; then
  control_loop &
else
  echo "Redis-Relay nicht konfiguriert (REDIS_HOST/CONTROL_CHANNEL/CONTAINER_NAME fehlen) – nur Pull-Modell aktiv."
fi

# Container-Lebensdauer an den Supervisor koppeln: läuft, solange Liquidsoap
# (neu) gestartet werden soll. Beendet sich erst auf expliziten Stop.
supervise_liquidsoap
