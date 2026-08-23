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

# Stereo Tool (Thimeo): proprietäre Shared-Library + .sts-Presets. Werden nur für
# Stationen genutzt, die ein Admin freigeschaltet hat. Pfade müssen zu
# config('radioring.stereo_tool.*') passen (libStereoTool.so + presets/<name>.sts).
# Inhalt liegt unter ./stereotool/ (siehe stereotool/README.md) und wird NICHT
# öffentlich verteilt – das Image gehört in eine private Registry.
COPY stereotool/ /opt/stereotool/

ENTRYPOINT ["/app/entrypoint.sh"]
