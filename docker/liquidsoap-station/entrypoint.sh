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

if [[ -n "${REDIS_HOST:-}" && -n "${CONTROL_CHANNEL:-}" && -n "${CONTAINER_NAME:-}" ]]; then
  control_loop &
else
  echo "Redis-Relay nicht konfiguriert (REDIS_HOST/CONTROL_CHANNEL/CONTAINER_NAME fehlen) – nur Pull-Modell aktiv."
fi

# Container-Lebensdauer an den Supervisor koppeln: läuft, solange Liquidsoap
# (neu) gestartet werden soll. Beendet sich erst auf expliziten Stop.
supervise_liquidsoap
