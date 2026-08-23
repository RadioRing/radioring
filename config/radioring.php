<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Betriebsmodus
    |--------------------------------------------------------------------------
    | standalone = eine Installation, ein Mandant, eine gemeinsame Bibliothek.
    |              Eingeladene Nutzer treten diesem einen Mandanten bei.
    | cloud      = viele unabhängige Mandanten. Jede Registrierung eröffnet einen
    |              eigenen Mandanten mit eigener Bibliothek; zusätzlich stehen
    |              Stations-Quota, Impersonation und Konto-Sperren zur Verfügung.
    |
    | Der Schalter steuert Routen und Sichtbarkeit. Das Datenmodell ist in beiden
    | Modi identisch – Standalone hat schlicht genau einen Mandanten.
    |
    */
    'mode' => env('RADIORING_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Container-Steuerung
    |--------------------------------------------------------------------------
    | docker    = direkt gegen die Docker Engine API (Standard). Empfohlen ueber
    |             einen vorgeschalteten Socket-Proxy, siehe SECURITY.md.
    | portainer = Legacy-Treiber ueber die Portainer-API (services.portainer.*).
    |
    | DOCKER_HOST nutzt die uebliche Docker-Syntax:
    |   tcp://dockerproxy:2375       -> Socket-Proxy (empfohlen)
    |   unix:///var/run/docker.sock  -> Socket direkt in den Container gemountet
    |
    | station_network: benanntes Docker-Netz, dem neue Station-Container beitreten.
    | Leer = Default-Bridge; dann muss LIQUIDSOAP_API_URL oeffentlich erreichbar sein.
    | Das Netz muss im Compose einen expliziten `name:` haben, sonst haengt der Name
    | vom Installationsverzeichnis ab.
    |
    | pull_timeout: das Station-Image ist mehrere hundert MB gross, Laravels
    | Standard-Timeout von 30 Sekunden reicht fuer einen Kaltstart nicht.
    |
    */
    'container_driver' => env('CONTAINER_DRIVER', 'docker'),

    'docker' => [
        'host' => env('DOCKER_HOST', 'unix:///var/run/docker.sock'),
        'api_version' => env('DOCKER_API_VERSION', 'v1.43'),
        'station_network' => env('DOCKER_STATION_NETWORK', ''),
        'timeout' => (int) env('DOCKER_TIMEOUT', 30),
        'connect_timeout' => (int) env('DOCKER_CONNECT_TIMEOUT', 5),
        'pull_timeout' => (int) env('DOCKER_PULL_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gueltigkeit der Auslieferungs-URLs
    |--------------------------------------------------------------------------
    | Mediendateien und vorbereitete externe Inhalte werden ueber signierte URLs an
    | Liquidsoap ausgeliefert, nicht mehr mit dem api_token im Query-String.
    |
    | Grosszuegig bemessen: der Pull-Cursor laeuft dem Airplay per prefetch voraus, und
    | ein Hard-Start kann Items zurueckhalten. Laeuft eine Signatur vorher ab, entsteht
    | Stille auf Sendung. Sechs Stunden sind sicher und immer noch ein gewaltiger
    | Unterschied zu einem Token mit unbegrenztem Vollzugriff.
    |
    */
    'delivery_url_ttl_seconds' => (int) env('DELIVERY_URL_TTL_SECONDS', 21600),

    /*
    |--------------------------------------------------------------------------
    | Adbreak-Signal-Datei
    |--------------------------------------------------------------------------
    | Absoluter Pfad zur MP3-Datei, die als Platzhalter für den laut.fm
    | START_ADBREAK-Befehl in schedule.liq genutzt wird. Liquidsoap annotiert
    | diese Datei mit title="START_ADBREAK", damit laut.fm den Werbeblock startet.
    |
    */
    'adbreak_signal_path' => env('ADBREAK_SIGNAL_PATH', '/opt/ad_break.mp3'),

    /*
    |--------------------------------------------------------------------------
    | Nachrichten-Konfiguration
    |--------------------------------------------------------------------------
    | Dauer der Nachrichten in Sekunden (Standard: 5 Minuten).
    | Pfad zur Nachrichten-Audiodatei oder leer lassen um nur die Zeitplanung
    | zu nutzen (Liquidsoap muss selbst die Nachrichten-Quelle einbinden).
    |
    */
    'news_duration_seconds' => (int) env('NEWS_DURATION_SECONDS', 300),

    'news_signal_path' => env('NEWS_SIGNAL_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Audio-Übergänge externer Quellen
    |--------------------------------------------------------------------------
    | Pro ExternalSource (Checkbox) aktivierbar. trim: Schwelle, unter der das
    | offline-Trimmen (ffmpeg silenceremove) führende Stille als solche wertet.
    | fade_in_seconds: Dauer des sanften Einblendens beim Ausspielen (fade.in
    | im Liquidsoap-Script, gesteuert per liq_fade_in-Annotation der /next-API).
    |
    */
    'silence_trim_threshold_db' => (float) env('SILENCE_TRIM_THRESHOLD_DB', -45.0),

    'fade_in_seconds' => (float) env('FADE_IN_SECONDS', 2.0),

    /*
    |--------------------------------------------------------------------------
    | Musik-Rotation: Titel-Cooldown
    |--------------------------------------------------------------------------
    | Reduziert Wiederholungen desselben Titels über den Tag. Innerhalb des
    | Cooldown-Fensters bekommt ein erneut gewählter Titel eine abklingende
    | Strafe (gerade gespielt = volle Strafe, am Fensterende ~0). Weiche Regel:
    | reicht das Material nicht, wird trotzdem aufgefüllt (least-penalty).
    |
    */
    'rotation' => [
        'title_cooldown_seconds' => (int) env('ROTATION_TITLE_COOLDOWN_SECONDS', 28800), // 8h
        'title_penalty' => (int) env('ROTATION_TITLE_PENALTY', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Liquidsoap-API-URL
    |--------------------------------------------------------------------------
    | Basis-URL, unter der der Liquidsoap-Container die RadioRing-API erreicht.
    | Kann von APP_URL abweichen, wenn der Container nicht auf demselben Host
    | läuft (lokal z.B. http://host.docker.internal:8000). Leer = APP_URL.
    |
    */
    'liquidsoap_api_url' => env('LIQUIDSOAP_API_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Lautheits-Normalisierung (LUFS)
    |--------------------------------------------------------------------------
    | Normalisiert jeden Track auf eine einheitliche Lautheit. Die Messung läuft
    | OFFLINE beim Upload (AnalyzeMediaLoudnessJob → ffmpeg/loudnorm, EBU R128);
    | das Ergebnis liegt in media_files. Beim Ausspielen annotiert die /next-API
    | pro Track den daraus berechneten liq_amplify-Gain, amplify(override="liq_amplify")
    | im Streamer wendet ihn an. Bewusst KEINE Live-Autocue-Messung – die crashte
    | bei defekten MP3s den ganzen Liquidsoap-Prozess. Der Live-Eingang (Harbor)
    | bleibt unberührt. target_lufs leer = -14 LUFS; typisch -16 bis -14 LUFS.
    | max_true_peak_dbtp begrenzt die Gain-Anhebung gegen Clipping (dBTP).
    |
    */
    'loudness' => [
        'enabled' => filter_var(env('LOUDNESS_NORMALIZATION', true), FILTER_VALIDATE_BOOLEAN),
        'target_lufs' => env('LOUDNESS_TARGET_LUFS') !== null ? (float) env('LOUDNESS_TARGET_LUFS') : null,
        'max_true_peak_dbtp' => (float) env('LOUDNESS_MAX_TRUE_PEAK_DBTP', -1.0),
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stereo Tool (Thimeo) – optionales Audio-Processing pro Station
    |--------------------------------------------------------------------------
    | CPU-intensives finales Processing über den nativen Liquidsoap-stereotool-
    | Operator. Wird pro Station von einem Admin freigeschaltet (stereo_tool_enabled),
    | der Betreiber hinterlegt seinen eigenen Thimeo-Lizenzschlüssel und wählt ein
    | Preset. Die proprietäre Shared-Library und die .sts-Presets liegen im (privaten)
    | Station-Image unter diesen Pfaden – NICHT im Repo eingecheckt.
    |
    */
    'stereo_tool' => [
        'library_file' => env('STEREO_TOOL_LIBRARY_FILE', '/opt/stereotool/libStereoTool.so'),
        'presets_path' => env('STEREO_TOOL_PRESETS_PATH', '/opt/stereotool/presets'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Station-Container (Liquidsoap)
    |--------------------------------------------------------------------------
    | Docker-Image des persistenten Liquidsoap-Station-Containers (via GitHub
    | Actions nach GHCR gebaut) und das Label, mit dem RadioRing seine Container
    | in Portainer markiert.
    |
    */
    'station_image' => env('STATION_IMAGE', 'ghcr.io/radioring/liquidsoap-station:latest'),

    'station_managed_by' => env('STATION_MANAGED_BY', 'radioring'),

    /*
    |--------------------------------------------------------------------------
    | Live-Streaming-Eingang (input.harbor) + Traefik-Veröffentlichung
    |--------------------------------------------------------------------------
    | Jeder Station-Container nimmt über input.harbor eine Live-Übernahme von
    | außen entgegen (Icecast-Source, Klartext – wie bei laut.fm).
    |
    | Da Icecast-Source kein HTTP ist und im Klartext kein Host-Routing über Traefik
    | möglich ist, bekommt jede Station einen EIGENEN, eindeutigen TCP-Port, der direkt
    | vom Container auf den Host veröffentlicht wird (Docker-PortBindings, ohne Traefik).
    | Der Encoder verbindet auf {domain}:{port} (Klartext). 'domain' ist nur der
    | Anzeige-Host (alle {slug}.{domain} zeigen auf die Server-IP); unterschieden wird
    | per Port. Leer = keine Veröffentlichung (lokal/Dev).
    |
    | Die Host-Ports aus diesem Bereich müssen in der Firewall offen sein.
    |
    */
    'stream' => [
        'domain' => env('STREAM_DOMAIN', ''),
        'port_min' => (int) env('STREAM_PORT_MIN', 8001),
        'port_max' => (int) env('STREAM_PORT_MAX', 8099),
        'mount' => env('STREAM_MOUNT', '/live'),
        'username' => env('STREAM_USERNAME', 'source'),
    ],

    /*
    | Optionaler Registry-Auth für private GHCR-Images (Portainer X-Registry-Auth).
    | Leer lassen, wenn das Image öffentlich ist.
    */
    'registry_username' => env('STATION_REGISTRY_USERNAME', ''),
    'registry_password' => env('STATION_REGISTRY_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Liquidsoap-Command-Relay (Redis pub/sub)
    |--------------------------------------------------------------------------
    | Kanal, über den RadioRing Live-Befehle (skip/stop/restart) an die
    | Station-Container schickt. Der Container subscribt darauf und leitet die
    | Befehle per Telnet an Liquidsoap weiter. Beide Seiten müssen denselben
    | Kanalnamen verwenden.
    |
    */
    'control_channel' => env('LIQUIDSOAP_CONTROL_CHANNEL', 'radioring_station_control'),

    /*
    | Redis-Host, unter dem der STATION-Container Redis erreicht. Kann von
    | REDIS_HOST abweichen (der Container läuft evtl. auf einem anderen Host als
    | die App). Leer = REDIS_HOST aus der DB-Config.
    */
    'station_redis_host' => env('STATION_REDIS_HOST', ''),

    /*
    |--------------------------------------------------------------------------
    | Build-ID
    |--------------------------------------------------------------------------
    | Used to show the short-sha from git if it is a snapshot from main branch
    | If this is a tagged release it should display the version number.
    |
    */
    'version' => [
        'name' => env('APP_VERSION', ''),
        'commit' => env('APP_COMMIT', ''),
        'repository' => env('APP_REPOSITORY', 'radioring/radioring'),
    ],
];
