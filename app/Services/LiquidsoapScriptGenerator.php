<?php

namespace App\Services;

use App\Enums\StereoToolPreset;
use App\Models\Station;
use App\Models\StationOutput;

class LiquidsoapScriptGenerator
{
    /**
     * Generiert das vollständige .liq-Script für eine Station.
     *
     * Pull-Modell: request.dynamic ruft die /next-API ab, on_metadata meldet
     * Now-Playing zurück, input.harbor erlaubt Live-Takeover und
     * output.icecast bedient jeden aktivierten Ausgang.
     */
    public function generate(Station $station): string
    {
        $apiUrl = rtrim(config('radioring.liquidsoap_api_url') ?: config('app.url'), '/');
        $slug = $station->slug;
        $token = $station->api_token;

        $livePort = $station->stream?->live_port ?? 8005;
        $livePassword = $station->stream?->live_password ?? 'changeme';

        $outputs = $station->outputs()->where('enabled', true)->get();

        $lines = [];
        $loudnessEnabled = (bool) config('radioring.loudness.enabled', true);

        $lines[] = '# Auto-generiert von RadioRing – nicht manuell editieren';
        $lines[] = 'settings.log.level.set(3)';
        $lines[] = 'settings.init.allow_root.set(true)';
        $lines[] = 'settings.init.force_start.set(true)';
        $lines[] = 'settings.server.telnet.set(true)';
        $lines[] = 'server.telnet(port=1234)';

        $lines[] = '';
        $lines[] = "api_url = \"{$apiUrl}\"";
        $lines[] = "slug = \"{$slug}\"";
        $lines[] = "token = \"{$token}\"";
        $lines[] = '';
        $lines[] = $this->safeHttpProtocols();
        $lines[] = '';
        $lines[] = $this->nextTrackFunction();
        $lines[] = '';

        // prefetch=3: lädt bis zu 3 Tracks im Voraus herunter, während der
        // aktuelle läuft → nahtlose Übergänge, Puffer gegen Latenz/kurze Tracks.
        // timeout=60: erlaubt auch größere Dateien (url-Sendungen) zu laden.
        $lines[] = 'source = request.dynamic(id="radioring", prefetch=3, retry_delay=1., timeout=60., next_track)';

        // Quelle, die in den fallback geht. amplify() liefert eine generische Source
        // ohne die request.dynamic-Methoden (set_queue/skip), die flush_and_skip
        // braucht – daher NICHT "source" überschreiben, sondern eine eigene Variable
        // ableiten und nur diese weiterverwenden.
        $programSource = 'source';

        if ($loudnessEnabled) {
            // Pro-Track-Lautheitskorrektur aus dem liq_amplify-Metadatum, das die
            // /next-API pro Track annotiert (offline per ffmpeg gemessen). Basiswert 1.0
            // (0 dB) greift, wenn keine Messung vorliegt (Live-Übernahme, ungemessene
            // Datei). KEINE Live-Autocue-Messung mehr – die crashte bei defekten MP3s
            // den gesamten Liquidsoap-Prozess (siehe loudness-crash-und-offline-messung.md).
            $lines[] = 'normalized = amplify(1., override="liq_amplify", source)';
            $programSource = 'normalized';
        }

        // Per-Element-Fade-in: duration=0. lässt Übergänge standardmäßig hart; nur Tracks
        // mit liq_fade_in-Annotation (z.B. externe News, in der UI aktiviert) blenden ein.
        // track_sensitive=true ist zwingend: der Default von fade.in ist seit Liquidsoap 2.2
        // FALSE, dann blendet es nur EINMAL beim Start der Source ein (source.on_frame +
        // memoize) und ignoriert jeden Trackwechsel – die liq_fade_in-Annotation lief damit
        // ins Leere. Mit true hängt der Fade an source.on_track und liest die Annotation
        // des jeweils startenden Elements.
        $lines[] = "faded = fade.in(track_sensitive=true, override_duration=\"liq_fade_in\", duration=0., {$programSource})";
        $programSource = 'faded';

        $lines[] = '';
        $lines[] = $this->hardCutCommand();
        $lines[] = '';
        $lines[] = $this->liveStatusCallbacks();
        $lines[] = '';
        $lines[] = "live = input.harbor(\"live\", port={$livePort}, password=\"{$livePassword}\", buffer=5., max=60., on_connect=on_live_connect, on_disconnect=on_live_disconnect)";
        $lines[] = "radio = fallback(track_sensitive=false, [live, {$programSource}, blank()])";

        if ($station->stereoToolActive()) {
            $lines[] = '';
            $lines[] = $this->stereoToolBlock($station);
        }

        $lines[] = '';
        $lines[] = $this->nowPlayingCallback();
        $lines[] = '';

        foreach ($outputs as $output) {
            $lines[] = $this->outputBlock($output, $station);
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Registriert das CRASH-SICHERE Request-Protokoll "safe:".
     *
     * request.dynamic löst die von /next gelieferte Track-URL auf. Würde diese als
     * blanke http(s)-URL geliefert, übernähme das Liquidsoaps eingebauter
     * http-Protokoll-Resolver (protocols.liq) und lädt sie per http.get herunter.
     * Bricht dieser Download ab – z. B. CURLE_RECV_ERROR, wenn laut.fm oder unser
     * Webserver die Verbindung droppt – wirft der eingebaute Resolver einen
     * UNCAUGHT Runtime-Error, der die GESAMTE Engine killt (der Supervisor startet
     * sie dann mit Audio-Lücke neu). Das try/catch in next_track schützt nur den
     * /next-Abruf selbst, nicht diesen nachgelagerten URL-Download im prefetch.
     *
     * Die eingebauten Protokolle http/https lassen sich nicht überschreiben
     * ("Plugin already registered"). Daher liefert /next die URLs mit "safe:"-Prefix
     * (z. B. safe:https://...), und dieses eigene Protokoll lädt sie mit try/catch:
     * bei Fehler liefern wir eine leere Liste – die Auflösung scheitert sauber,
     * request.dynamic zieht nach retry_delay den nächsten Track von /next statt zu
     * crashen. Suffix ".mp3" als Decoder-Hint (alle RadioRing-Ausspielwege sind mp3).
     */
    private function safeHttpProtocols(): string
    {
        return <<<'LIQ'
def protocol_safe(~rlog, ~maxtime, arg) =
  ignore(maxtime)
  tmp = file.temp("liq_safe", ".mp3")
  try
    response = http.get(arg)
    if response.status_code >= 200 and response.status_code < 300 then
      file.write(data="#{response}", tmp)
      [tmp]
    else
      rlog("safe-Auflösung von #{arg}: HTTP #{response.status_code}, übersprungen.")
      file.remove(tmp)
      []
    end
  catch _ do
    rlog("safe-Auflösung von #{arg} fehlgeschlagen (CurlException o.ä.), übersprungen.")
    file.remove(tmp)
    []
  end
end
protocol.add(temporary=true, "safe", protocol_safe)
LIQ;
    }

    private function nextTrackFunction(): string
    {
        // http.get wirft bei Verbindungsabbrüchen (z. B. CURLE_RECV_ERROR, wenn der
        // Webserver die Verbindung kurz droppt) einen Runtime-Error. Ohne try/catch
        // wäre dieser "uncaught" und würde die GESAMTE Liquidsoap-Engine killen – der
        // Container liefe danach tot weiter. Bei Fehler liefern wir null(), dann zieht
        // request.dynamic nach retry_delay einfach erneut.
        return <<<'LIQ'
def next_track() =
  body = ref("")
  try
    body := string.trim(http.get(
      headers=[("Authorization", "Bearer #{token}")],
      "#{api_url}/api/liquidsoap/#{slug}/next"
    ))
  catch err do
    log(level=2, label="radioring", "next_track: HTTP-Fehler beim /next-Abruf, überspringe (request.dynamic versucht erneut).")
    ignore(err)
  end
  if body() == "" then
    null()
  else
    request.create(body())
  end
end
LIQ;
    }

    private function hardCutCommand(): string
    {
        // Wird vom Container-Relay als Telnet-Befehl "radioring.flush_and_skip"
        // aufgerufen (siehe docker/liquidsoap-station/entrypoint.sh). Ein einfacher
        // skip würde nur den laufenden Track droppen – die bis zu 3 per prefetch
        // bereits vorgeladenen Tracks (z. B. Überhang der Vorstunde) würden
        // weiterlaufen. Für einen harten Stundencut muss die Prefetch-Queue erst
        // geleert werden, damit Liquidsoap sofort neu von /next zieht.
        return <<<'LIQ'
def flush_and_skip(_) =
  source.set_queue([])
  source.skip()
  "ok"
end
server.register(namespace="radioring", description="Prefetch-Queue leeren und zum nächsten Track springen", "flush_and_skip", flush_and_skip)
LIQ;
    }

    private function nowPlayingCallback(): string
    {
        // title/artist werden mitgeschickt, damit das Portal die Metadaten einer
        // Live-Übernahme (input.harbor, ohne radioring_item_id) anzeigen kann.
        // json.stringify übernimmt das Escaping (Titel können Anführungszeichen enthalten).
        return <<<'LIQ'
def on_meta(m) =
  payload = json.stringify({
    item_id = m["radioring_item_id"],
    filename = m["filename"],
    title = m["title"],
    artist = m["artist"]
  })
  try
    ignore(http.post(
      headers=[("Authorization", "Bearer #{token}"), ("Content-Type", "application/json")],
      data=payload,
      "#{api_url}/api/liquidsoap/#{slug}/now-playing"
    ))
  catch err do
    log(level=2, label="radioring", "on_meta: now-playing-POST fehlgeschlagen (ignoriert).")
    ignore(err)
  end
end
radio.on_metadata(on_meta)
LIQ;
    }

    /**
     * Meldet die harbor-Verbindung selbst (on_connect/on_disconnect) an RadioRing –
     * unabhängig von Metadaten-Wechseln. So wird eine laufende Live-Sendung sofort
     * erkannt, auch wenn der Encoder den Titel lange nicht ändert.
     */
    private function liveStatusCallbacks(): string
    {
        return <<<'LIQ'
def live_status(connected) =
  try
    ignore(http.post(
      headers=[("Authorization", "Bearer #{token}"), ("Content-Type", "application/json")],
      data="{\"connected\":#{connected}}",
      "#{api_url}/api/liquidsoap/#{slug}/live"
    ))
  catch err do
    log(level=2, label="radioring", "live_status: live-POST fehlgeschlagen (ignoriert).")
    ignore(err)
  end
end
def on_live_connect(_) = live_status("true") end
def on_live_disconnect() = live_status("false") end
LIQ;
    }

    /**
     * Finales Stereo-Tool-Processing (Thimeo) auf die fertige radio-Source – also
     * inklusive Live-Übernahme. Bewusst EINMAL auf "radio" statt pro Output, damit die
     * teure Verarbeitung nur einmal läuft. Der native stereotool-Operator lädt die
     * proprietäre Shared-Library, den Lizenzschlüssel und das gewählte Preset (.sts).
     * Nur aktiv, wenn die Station freigeschaltet UND vollständig konfiguriert ist
     * (siehe Station::stereoToolActive) – sonst liefe Stereo Tool im Demo-Modus.
     */
    private function stereoToolBlock(Station $station): string
    {
        $libraryFile = (string) config('radioring.stereo_tool.library_file');
        $licenseKey = addslashes((string) $station->stereo_tool_license_key);
        $preset = StereoToolPreset::from($station->stereo_tool_preset)->filePath();

        return <<<LIQ
        radio = stereotool(library_file="{$libraryFile}", license_key="{$licenseKey}", preset="{$preset}", radio)
        LIQ;
    }

    private function outputBlock(StationOutput $output, Station $station): string
    {
        if ($output->isInternal()) {
            return $this->internalOutputBlock($output, $station);
        }

        $password = $output->password ?? '';
        $username = $output->username ?: 'source';

        return <<<LIQ
output.icecast(
  %mp3(bitrate={$output->bitrate}),
  host="{$output->host}",
  port={$output->port},
  mount="{$output->mount}",
  user="{$username}",
  password="{$password}",
  fallible=true,
  radio
)
LIQ;
    }

    /**
     * Output to the station's own Icecast sidecar.
     *
     * The target is the container name inside the Docker network, not the public address:
     * going through Traefik would hairpin and hit the TLS endpoint. Host, port and password
     * are therefore derived here instead of stored, so a rotated password stays valid
     * without touching any data.
     */
    private function internalOutputBlock(StationOutput $output, Station $station): string
    {
        $host = $station->icecastContainerName();
        $password = $station->ensureStream()->icecast_password ?? '';
        $mount = '/'.$output->mountName();
        $name = addslashes($station->name);

        return <<<LIQ
output.icecast(
  %mp3(bitrate={$output->bitrate}),
  host="{$host}",
  port=8000,
  mount="{$mount}",
  user="source",
  password="{$password}",
  name="{$name}",
  public=false,
  fallible=true,
  radio
)
LIQ;
    }
}
