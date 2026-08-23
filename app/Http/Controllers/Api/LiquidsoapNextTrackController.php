<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPlaylistItem;
use App\Models\Station;
use App\Services\LiquidsoapStateService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class LiquidsoapNextTrackController extends Controller
{
    public function __invoke(string $slug, LiquidsoapStateService $stateService): Response
    {
        $station = Station::where('slug', $slug)->firstOrFail();

        $item = $stateService->pullNextItem($station);

        if (! $item) {
            // Kein Rundown → Liquidsoap fällt auf Silence zurück
            return response('', 200);
        }

        // adbreak-Items: annotate-String für laut.fm
        if ($item->source_type === 'adbreak') {
            $signalPath = config('radioring.adbreak_signal_path', '');

            return $this->plain("annotate:radioring_item_id=\"{$item->id}\",title=\"START_AD_BREAK\",artist=\"START_AD_BREAK\":{$signalPath}");
        }

        // Externe dynamische Quellen (Syndication/News/Wetter als ExternalSource):
        // die kurz vor Ausspielung vorbereitete lokale Kopie ausliefern (inkl. liq_amplify).
        // Falls PrepareUpcomingHttpItemsJob noch nicht gelaufen ist, wird die Datei hier
        // direkt synchron heruntergeladen – so erhält Liquidsoap stets eine lokale Laravel-URL
        // statt der externen URL, die bei laut.fm-Quellen Zugangsdaten enthalten kann.
        if ($item->source_type === 'external') {
            // Inline-Vorbereitung: synchron holen falls der Hintergrundjob noch nicht gelaufen ist.
            if (! ($item->prepared_path && Storage::disk('local')->exists($item->prepared_path))) {
                $this->prepareInline($item, $station->slug);
            }

            // Nach erfolgloser Vorbereitung: Item überspringen, damit keine Zugangsdaten
            // an den Liquidsoap-Container weitergegeben werden und das Programm nicht stockt.
            if (! ($item->prepared_path && Storage::disk('local')->exists($item->prepared_path))) {
                return $this->__invoke($slug, $stateService);
            }

            $url = $this->signedDeliveryUrl('liquidsoap.prepared', [
                'slug' => $station->slug,
                'item' => $item->id,
            ]);

            $annotations = "radioring_item_id=\"{$item->id}\"";
            $annotations .= $this->metadataAnnotations($item->title, null);

            if (config('radioring.loudness.enabled', true) && $item->externalSource?->normalize) {
                $gainDb = $item->loudnessGainDb();

                if ($gainDb !== null) {
                    $annotations .= ",liq_amplify=\"{$gainDb} dB\"";
                }
            }

            // Sanftes Einblenden beim Ausspielen: fade.in(override_duration="liq_fade_in")
            // im Streamer-Script liest diese Annotation; ohne sie bleibt der Übergang hart.
            if ($item->externalSource?->fade_in) {
                $fadeSeconds = (float) config('radioring.fade_in_seconds', 2.0);
                $annotations .= ",liq_fade_in=\"{$fadeSeconds}\"";
            }

            return $this->plain("annotate:{$annotations}:{$this->safeUri($url)}");
        }

        // news/weather-Items: authentifizierte laut.fm-RadioAdmin-URL aus den
        // Credentials des laut.fm-Ausgangs der Station.
        if (in_array($item->source_type, ['news', 'weather', 'news_weather'], true)) {
            $url = $this->lautfmNewsUrl($station, $item->source_type);

            if ($url === null) {
                // Kein laut.fm-Ausgang / keine Credentials → überspringen, nächsten Track holen
                return $this->__invoke($slug, $stateService);
            }

            return $this->plain("annotate:radioring_item_id=\"{$item->id}\":{$this->safeUri($url)}");
        }

        // Normale Tracks: authentifizierte HTTP-URL, adressiert per Medien-ID (eine
        // Station kann auch fremde, verlinkte Dateien senden). Annotiert mit der Item-ID,
        // damit der now-playing-Callback den Track eindeutig zuordnen kann.
        // Die Item-ID reist mit: wurde die Datei seit der Generierung ersetzt, liefert
        // der Media-Controller die am Item eingefrorene Fassung aus.
        $url = $this->signedDeliveryUrl('liquidsoap.media', [
            'slug' => $station->slug,
            'mediaFile' => $item->media_file_id,
            'item' => $item->id,
        ]);

        $annotations = "radioring_item_id=\"{$item->id}\"";

        // Titel/Interpret aus der DB mitschicken, damit der AUSGEHENDE Stream die
        // (korrigierbaren) DB-Werte zeigt statt der evtl. veralteten ID3-Tags der Datei.
        // Bewusst der Live-DB-Stand der Mediendatei, nicht der eingefrorene
        // Rundown-Snapshot ($item->title) – so wirken Korrekturen ohne Neu-Generierung.
        $annotations .= $this->metadataAnnotations(
            $item->mediaFile?->title ?? $item->title,
            $item->mediaFile?->artist,
        );

        // Offline gemessene Lautheitskorrektur als liq_amplify-Override mitgeben.
        // amplify(override="liq_amplify") im Streamer-Script wendet sie pro Track an;
        // fehlt der Wert (noch nicht gemessen), greift der Basiswert 1.0 (0 dB).
        // Normalerweise der Live-DB-Stand (so wirkt eine spaeter nachgeholte Messung auch
        // in bereits generierten Rundowns). Spielt hier aber eine ersetzte Fassung, gilt
        // deren eingefrorener Messwert – der neue Wert gehoert zur neuen Datei.
        if (config('radioring.loudness.enabled', true)) {
            $gainDb = $item->supersededPath() !== null
                ? $item->loudnessGainDb()
                : $item->mediaFile?->loudnessGainDb();

            if ($gainDb !== null) {
                $annotations .= ",liq_amplify=\"{$gainDb} dB\"";
            }
        }

        // Optionales sanftes Einblenden pro Datei (z.B. Jingles). fade.in im Streamer-Script
        // liest die liq_fade_in-Annotation; ohne sie bleibt der Übergang hart. Live-DB-Stand,
        // damit das Häkchen ohne Rundown-Neugenerierung greift.
        if ($item->mediaFile?->fade_in) {
            $fadeSeconds = (float) config('radioring.fade_in_seconds', 2.0);
            $annotations .= ",liq_fade_in=\"{$fadeSeconds}\"";
        }

        return $this->plain("annotate:{$annotations}:{$this->safeUri($url)}");
    }

    /**
     * Prefixt eine http(s)-URL mit dem crash-sicheren "safe:"-Request-Protokoll des
     * Streamer-Scripts (siehe LiquidsoapScriptGenerator::safeHttpProtocols). So lädt
     * NICHT der eingebaute http-Resolver die Datei (dessen CurlException die ganze
     * Engine killt), sondern unser try/catch-gekapselter Resolver.
     */
    private function safeUri(string $url): string
    {
        return "safe:{$url}";
    }

    /**
     * Lädt eine externe Quelle synchron herunter und speichert sie als lokale Kopie –
     * Fallback, wenn PrepareUpcomingHttpItemsJob noch nicht im Vorlauf gelaufen ist.
     *
     * Nach dem Aufruf ist $item->prepared_path gesetzt (und die Datei vorhanden),
     * sofern der Download erfolgreich war.
     */
    private function prepareInline(GeneratedPlaylistItem $item, string $stationSlug): void
    {
        $url = $item->externalSource?->resolveUrl();

        if ($url === null) {
            return;
        }

        $path = "stations/{$stationSlug}/prepared/{$item->id}.mp3";

        try {
            $response = Http::timeout(30)->get($url);

            if ($response->successful() && $response->body() !== '') {
                Storage::disk('local')->put($path, $response->body());
                $item->update(['prepared_path' => $path, 'prepared_at' => now()]);
            }
        } catch (\Throwable) {
            // Download fehlgeschlagen – prepared_path bleibt null.
        }
    }

    /**
     * Baut die title/artist-Annotation aus DB-Werten – leere Felder werden weggelassen.
     */
    private function metadataAnnotations(?string $title, ?string $artist): string
    {
        $out = '';

        if ($title !== null && $title !== '') {
            $out .= ',title="'.$this->annotateValue($title).'"';
        }

        if ($artist !== null && $artist !== '') {
            $out .= ',artist="'.$this->annotateValue($artist).'"';
        }

        return $out;
    }

    /**
     * Neutralisiert Zeichen, die den Liquidsoap-annotate-Parser zerschießen würden
     * (Anführungszeichen, Backslash, Zeilenumbrüche). Bewusst ersetzen statt escapen –
     * das ist versionsunabhängig sicher und der Verlust (» " « → » ' «) ist kosmetisch.
     */
    private function annotateValue(string $value): string
    {
        $value = str_replace(['\\', '"'], ['', "'"], $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Baut eine signierte Auslieferungs-URL fuer Liquidsoap.
     *
     * Frueher haengte hier der api_token als Query-Parameter an. Der landet damit in
     * jedem Proxy- und Access-Log, und wer ihn dort liest, kann als Bearer-Token auch
     * /script abrufen - inklusive Icecast-Ausgangs- und Harbor-Passwort. Eine Signatur
     * gilt dagegen nur fuer diese eine URL und laeuft ab.
     *
     * Bewusst RELATIV signiert: Laravel prueft eine absolute Signatur gegen den
     * tatsaechlichen Request-Host. Die App ist aber je nach Aufbau unter APP_URL oder
     * intern unter LIQUIDSOAP_API_URL erreichbar; eine host-gebundene Signatur wuerde
     * dann fehlschlagen.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function signedDeliveryUrl(string $route, array $parameters): string
    {
        $ttl = (int) config('radioring.delivery_url_ttl_seconds', 21600);

        $relative = URL::temporarySignedRoute($route, now()->addSeconds($ttl), $parameters, absolute: false);

        $base = rtrim((string) (config('radioring.liquidsoap_api_url') ?: config('app.url')), '/');

        return $base.$relative;
    }

    /**
     * Baut die authentifizierte laut.fm-RadioAdmin-URL für Nachrichten/Wetter.
     * Endpunkt-Segmente: 1 = Nachrichten+Wetter, 2 = Nachrichten, 3 = Wetter.
     *
     * @return string|null Null, wenn die Station keinen laut.fm-Ausgang mit Credentials hat.
     */
    private function lautfmNewsUrl(Station $station, string $sourceType): ?string
    {
        $output = $station->lautfmOutput();

        if (! $output || ! $output->username || ! $output->password) {
            return null;
        }

        $segment = match ($sourceType) {
            'news_weather' => 1,
            'news' => 2,
            'weather' => 3,
        };

        $mount = rawurlencode($output->mountName());
        $pass = rawurlencode($output->password);

        return "https://{$mount}:{$pass}@api.radioadmin.laut.fm/news/{$segment}";
    }

    private function plain(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
