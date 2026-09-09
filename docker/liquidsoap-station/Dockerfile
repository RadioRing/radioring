# Persistenter RadioRing Liquidsoap-Station-Container (Pull-Modell)
FROM savonet/liquidsoap:v2.2.5

USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl procps netcat-traditional ca-certificates redis-tools jq \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
RUN mkdir -p /app/liquidsoap /var/log/liquidsoap

COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# Adbreak-Signal-Datei (laut.fm START_AD_BREAK). Pfad muss zu
# config('radioring.adbreak_signal_path') passen.
COPY ad_break.mp3 /opt/ad_break.mp3

# Stereo Tool (Thimeo): proprietary shared library, shipped with Thimeo's permission and
# under Thimeo's licence, NOT under RadioRing's AGPL. See stereotool/LICENSE-Thimeo.md,
# which is copied along with it. Presets are NOT here: the entrypoint fetches the selected
# .sts from the API before every Liquidsoap start.
#
# The binary is not in git. CI fetches it into this directory from stereotool/sources.json
# before the build (see .github/workflows/liquidsoap-station.yml); a local build either has
# it placed here by hand or produces an image without Stereo Tool. The path must match
# config('radioring.stereo_tool.library_file').
COPY stereotool/ /opt/stereotool/

ENTRYPOINT ["/app/entrypoint.sh"]
