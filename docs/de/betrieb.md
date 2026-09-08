# RadioRing: Betrieb

Praktische Referenz für Installation, Konfiguration und den laufenden Betrieb.

Warum der Ausspielweg so aussieht, wie er aussieht, steht in
[`architecture.md`](../architecture.md). Das Bedrohungsmodell in
[`SECURITY.md`](../../SECURITY.md).

> Diese Fassung ist die führende. Die englische Übersetzung unter
> [`docs/en/operations.md`](../en/operations.md) kann nachhinken.

---

## 1. In einem Absatz

Die **Laravel-App** verwaltet Stationen, Playlisten und Rundowns. Pro Station startet sie
über die Docker-API einen dauerhaft laufenden **Liquidsoap-Container**. Der holt sich den
jeweils nächsten Track per HTTP von der App und streamt das Ergebnis an Icecast oder
laut.fm.

```
[ App-Container ]  ── Docker-API ──▶  [ Station-Container ]
  Web/Queue/Sched                        │ holt /script, /next, Medien per HTTPS
       ▲                                 ▼
       └──── /api/liquidsoap/{slug}/* ◀── streamt ──▶ Icecast / laut.fm
```

---

## 2. Installation

Der vorgesehene Weg ist der Installer:

```sh
curl -fsSL https://raw.githubusercontent.com/radioring/radioring/main/install.sh | sh
```

Er fragt nach der Panel-Domain, ob MySQL, Redis und Traefik mitgeliefert werden sollen und
wie Docker erreicht wird. Danach schreibt er `/opt/radioring/.env` und eine
`docker-compose.yml`, startet alles und gibt einen Einladungscode für die erste
Registrierung aus.

Nicht-interaktiv, etwa in einem Provisionierungsskript:

```sh
RR_APP_HOST=panel.example.com RR_DB=bundled RR_REDIS=bundled RR_PROXY=traefik \
  sh install.sh --yes
```

Jede Abfrage lässt sich über eine `RR_`-Variable vorbelegen, `--dir=` ändert das
Zielverzeichnis.

Aktualisieren:

```sh
cd /opt/radioring && ./update.sh          # ziehen und neu starten
cd /opt/radioring && ./update.sh --check  # nur prüfen, ob es neuere Images gibt
```

Ein erneuter Lauf des Installers auf einem bestehenden Verzeichnis wechselt in den
Reparaturmodus: vorhandene Werte werden zu Vorgaben, `APP_KEY` wird **nie** neu erzeugt,
und die bisherige `.env` wird vorher gesichert.

---

## 3. Konfiguration

Das alles schreibt der Installer. Die Tabellen sind für den Fall, dass du von Hand
nacharbeitest.

### 3.1 Anwendung

| Variable | Beispiel | Zweck |
|---|---|---|
| `APP_URL` | `https://panel.example.com` | Öffentliche URL |
| `APP_KEY` | `base64:...` | **Sichern.** Siehe Abschnitt 8. |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | In Produktion immer `false`: der Debug-Screen zeigt jeden Umgebungswert, auch Passwörter. |
| `APP_LOCALE` | `de` | Sprache der Oberfläche, `de` oder `en` |
| `APP_MODE` | `all` | `all` = Web, Queue und Scheduler in einem Container. Alternativ `web`, `queue`, `scheduler` zum Aufteilen. |
| `RADIORING_MODE` | `standalone` | Nur der Startwert des Betriebsmodus. Der wirksame liegt in der Datenbank, siehe Abschnitt 4. |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | | |
| `QUEUE_CONNECTION` | `database` | Rundowns und Container-Starts laufen als Jobs |

### 3.2 Container-Steuerung

| Variable | Beispiel | Zweck |
|---|---|---|
| `CONTAINER_DRIVER` | `docker` | `docker` oder `portainer` (Legacy) |
| `DOCKER_HOST` | `tcp://dockerproxy:2375` | Socket-Proxy (empfohlen) oder `unix:///var/run/docker.sock` |
| `DOCKER_API_VERSION` | `v1.43` | Leer = Vorgabe des Daemons |
| `DOCKER_STATION_NETWORK` | `radioring` | Benanntes Netz, dem die Station-Container beitreten. Leer = Default-Bridge, dann muss `LIQUIDSOAP_API_URL` öffentlich erreichbar sein. |
| `DOCKER_PULL_TIMEOUT` | `600` | Das Station-Image ist mehrere hundert MB groß, 30 Sekunden reichen für einen Kaltstart nicht. |
| `STATION_IMAGE` | `ghcr.io/radioring/liquidsoap-station:latest` | |
| `STATION_REGISTRY_USERNAME` / `_PASSWORD` | | Nur bei privater Registry |

> **Wer die Docker-API erreicht, ist effektiv root auf dem Host.** Der Socket-Proxy
> verkleinert die Angriffsfläche, er ist keine Sicherheitsgrenze. Siehe `SECURITY.md`.

Für den Legacy-Treiber: `PORTAINER_ENDPOINT`, `PORTAINER_TOKEN`, `PORTAINER_ENVIRONMENT`.

### 3.3 Ausspielung und Auslieferung

| Variable | Beispiel | Zweck |
|---|---|---|
| `LIQUIDSOAP_API_URL` | `http://app:8080` | Basis-URL, unter der der Container die App erreicht. Leer = `APP_URL`. Lokal `http://host.docker.internal:8000`. |
| `DELIVERY_URL_TTL_SECONDS` | `21600` | Gültigkeit der signierten Medien-URLs. Bewusst großzügig: der Pull-Cursor läuft voraus, ein Hard-Start kann Items zurückhalten. Zu kurz bedeutet Stille auf Sendung. |
| `LOUDNESS_NORMALIZATION` | `true` | Offline-Messung nach EBU R128 beim Upload |
| `LOUDNESS_TARGET_LUFS` | `-14` | |

### 3.4 Befehlskanal

Skip, Stop und Neustart laufen vom Dashboard über Redis zum Container.

| Variable | Beispiel | Zweck |
|---|---|---|
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | | App-Seite |
| `STATION_REDIS_HOST` | *(leer)* | Abweichende Adresse für den Container |
| `LIQUIDSOAP_CONTROL_CHANNEL` | `radioring_station_control` | Muss auf beiden Seiten gleich sein |

App und Container müssen **dieselbe Redis-Instanz** und denselben Kanal nutzen. Redis
pub/sub ist instanzweit, `REDIS_DB` betrifft nur Keys, nicht Kanäle.

### 3.5 Live-Eingang

| Variable | Beispiel | Zweck |
|---|---|---|
| `STREAM_DOMAIN` | `stream.example.com` | Leer = kein Live-Eingang |
| `STREAM_PORT_MIN` / `STREAM_PORT_MAX` | `8001` / `8099` | Ein Port je Station, auf dem Host veröffentlicht |
| `STREAM_MOUNT` / `STREAM_USERNAME` | `/live` / `source` | |

Braucht einen Wildcard-Eintrag `*.stream.example.com` auf den Server und den Portbereich
offen in der Firewall.

---

## 4. Betriebsmodus

| Modus | Verhalten |
|---|---|
| `standalone` | Ein Mandant. Eingeladene Nutzer treten ihm bei. Keine Stations-Quota, keine Impersonation, kein Sperren von Konten. |
| `cloud` | Viele Mandanten. Jede Registrierung eröffnet einen eigenen mit eigener Medienbibliothek. |

Der Modus liegt in der Datenbank und ist zur Laufzeit unter
**Admin → Instanz-Einstellungen** umschaltbar, ohne erneutes Deployment. `RADIORING_MODE`
liefert nur den Startwert, weil der Entrypoint bei jedem Start `config:cache` ausführt.

---

## 5. Befehle

Im Container: `docker compose exec -T app php artisan <befehl>`.

### Nutzer und Zugang

```sh
php artisan user:manage {email} [--verify] [--admin] [--ban] [--quota=N] [--password=...]
php artisan invite:manage --create --count=1 --note="..."
php artisan invite:manage --list
```

Ohne Optionen zeigt `user:manage` nur eine Übersicht des Kontos.

### Stationen

```sh
php artisan station:rotate-token {slug|id} [--force] [--no-restart]
```

Erzeugt einen neuen API-Token und den Container neu, weil der Token als Umgebungsvariable
in ihm steckt. Die Ausspielung unterbricht dabei kurz, deshalb fragt der Befehl vorher.

### Medien

```sh
php artisan media:rescan-tags [--station=slug] [--force] [--dry-run]
php artisan media:measure-loudness [--station=slug]
php artisan media:prune-chunks [--hours=2]
```

### Sicherungen

```sh
php artisan backup:run [--auto] [--passphrase=...] [--queue]
php artisan backup:restore {id|dateiname|pfad} [--passphrase=...] [--force]
```

`backup:run` schreibt ein Konfigurations-Backup nach `storage/app/private/backups` und
wendet danach die eingestellte Aufbewahrung an. `--auto` ist der naechtliche Lauf: er
verwendet die im Panel hinterlegte Passphrase. Details in [Abschnitt 8](#8-sicherungen).

### Diagnose

```sh
php artisan radioring:schedule-status {station}   # Cursor, laufender Track, Rundown
php artisan radioring:enforce-hard-starts         # sonst vom Scheduler
```

### Lokale Entwicklung

```sh
php artisan radioring:prepare-local-stream {station?} [--host=host.docker.internal] [--port=8000]
```

Richtet eine Station für den lokalen Docker-Test ein: legt einen Icecast-Ausgang an,
erzeugt einen Rundown für die aktuelle Stunde, setzt den State zurück und schreibt
`docker/.env`.

---

## 6. Geplante Jobs

Registriert in `routes/console.php`:

| Wann | Job | Zweck |
|---|---|---|
| täglich 22:00 | `GenerateDailyRundownsJob` | 24 Rundowns für den Folgetag aus dem Wochenraster |
| stündlich :55 | `PreloadNextRundownJob` | Rundown der Folgestunde sicherstellen |
| minütlich | `radioring:enforce-hard-starts` | Umschalten auf eine Stunde mit hartem Start |
| minütlich | `PrepareUpcomingHttpItemsJob` | Externe Quellen kurz vor Ausspielung holen |
| stündlich | `media:prune-chunks` | Verwaiste Upload-Chunks aufräumen |
| täglich, einstellbar | `backup:run --auto` | Konfigurations-Backup, nur wenn im Panel aktiviert |

**Ohne laufenden Scheduler und Queue-Worker entstehen keine Rundowns**, die Station fällt
nach der aktuellen Stunde in Stille. Mit `APP_MODE=all` laufen beide im App-Container. Wer
sie aufteilt, braucht einen Cron-Eintrag:

```
* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Container

### App-Container

FrankenPHP auf Port 8080, Healthcheck auf `/up`. Beim Start laufen `migrate --force`,
`storage:link` und die Config-, Route-, View- und Event-Caches.

Mit `APP_MODE=all` ist FrankenPHP PID 1, Queue-Worker und Scheduler laufen daneben in
Neustart-Schleifen. Stirbt FrankenPHP, endet der Container und Docker startet ihn neu.

### Station-Container

Werden **nicht** von der CI deployt, sondern von der App erzeugt, wenn du im Dashboard
**Start** drückst. Beim Hochfahren holt der Container sein `.liq`-Script von
`{LIQUIDSOAP_API_URL}/api/liquidsoap/{slug}/script`.

Änderungen an Ausgängen oder am Live-Eingang brauchen deshalb einen **Neustart des
Station-Containers**, kein Deployment der App.

---

## 8. Sicherungen

Zwei Dinge, und beide zählen:

1. **Konfiguration und Datenbank.** Alles außer den Audiodateien. Dafür gibt es die
   eingebaute Backup-Funktion, siehe unten.
2. **`storage/`.** Die Audiodateien. Bewusst **nicht** Teil der eingebauten Backups: eine
   Medienbibliothek ist um Größenordnungen größer als der Rest und will anders
   gesichert werden. Anleitung weiter unten.

Der `APP_KEY` verschlüsselt Station-Tokens sowie Ausgangs-, Live- und Partner-Passwörter.
**Ohne diesen Schlüssel sind diese Werte aus einer Datenbanksicherung nicht
wiederherstellbar.** Er steckt deshalb im Konfigurations-Backup mit drin. Ist der Schlüssel
verloren und liegt kein Backup vor, hilft nur: jeden Station-Token per
`station:rotate-token` rotieren und die Ausgangspasswörter neu eintragen.

### 8.1 Konfigurations-Backup

Im Panel unter **Administration → Backups**. Ein Backup enthält einen Datenbank-Dump, die
`.env` (falls die Installation eine hat) und ein Manifest mit dem `APP_KEY`. Zusammen ist
das ein vollständiger Satz Schlüssel zur Installation, deshalb:

- Ein Archiv, das den Server verlässt, bekommt eine **Passphrase**. Es wird dann mit
  AES-256-GCM verschlüsselt (`.zip.enc`). Eine verlorene Passphrase lässt sich nicht
  wiederherstellen, das Archiv ist damit wertlos.
- Der Download geht nur an angemeldete Administratoren. Es gibt bewusst keinen öffentlichen
  Link.

Automatische Backups laufen nächtlich zur eingestellten Uhrzeit, sofern der **Scheduler**
läuft (siehe Abschnitt 6). Die Aufbewahrung löscht die ältesten Archive, sobald die
eingestellte Anzahl erreicht ist; fehlgeschlagene Läufe bleiben in der Liste stehen, damit
ein wochenlang kaputtes Backup auffällt.

Auf der Kommandozeile:

```sh
docker compose exec -T app php artisan backup:run --passphrase='...'
```

Die Archive liegen im Container unter `storage/app/private/backups`, also im
`storage`-Volume. Wer sie außerhalb haben will, holt sie über das Panel oder kopiert sie
heraus:

```sh
docker compose cp app:/app/storage/app/private/backups ./backups
```

### 8.2 Wiederherstellen

Eine Wiederherstellung **ersetzt alle Daten** dieser Installation und läuft deshalb nur
auf der Kommandozeile, nie über das Panel.

```sh
# 1. Archiv bereitlegen: entweder liegt es schon im Backup-Verzeichnis der
#    Installation, oder du kopierst es hinein.
docker compose cp ./radioring-config-2026-09-08_030000-ab12.zip.enc \
    app:/app/storage/app/private/backups/

# 2. Wiederherstellen. Der Befehl zeigt zuerst Datum, Version und Datenbanktreiber
#    des Archivs und fragt dann nach.
docker compose exec app php artisan backup:restore \
    radioring-config-2026-09-08_030000-ab12.zip.enc --passphrase='...'

# 3. Station-Container neu starten, damit sie die wiederhergestellte
#    Konfiguration übernehmen (im Dashboard oder per Neustart des Stacks).
```

Statt des Dateinamens geht auch die ID aus der Backup-Liste oder ein absoluter Pfad.

Worauf der Befehl vorher prüft, und warum er notfalls abbricht:

- **Datenbanktreiber.** Ein SQLite-Archiv lässt sich nicht in eine MySQL-Installation
  zurücksichern und umgekehrt. Der Dump enthält das native Schema der Quelldatenbank.
- **`APP_KEY`.** Weicht der Schlüssel der Installation von dem im Archiv ab, bricht der
  Befehl ab. Die verschlüsselten Spalten wären sonst unlesbar. Der richtige Schlüssel
  steht im Archiv in `manifest.json` und in der Datei `env`. Setze ihn in der `.env` neben
  dem Compose-File, starte den Stack neu und wiederhole den Befehl. `--force` erzwingt die
  Wiederherstellung trotzdem, dann sind Station-Tokens und Passwörter danach neu zu
  setzen.

Nach der Wiederherstellung ist die Backup-Liste im Panel leer: sie zeigt auf Archive
dieses Hosts und wird deshalb nicht mitgesichert. Die Dateien im Backup-Verzeichnis
bleiben liegen.

Auf einen frischen Host: erst `install.sh` wie üblich, dann den `APP_KEY` aus dem Archiv
in die `.env` eintragen, Stack starten, Archiv hineinkopieren, `backup:restore`. Die
Mediendateien kommen getrennt zurück (siehe unten), sonst zeigen alle Rundowns auf
fehlende Dateien.

### 8.3 Mediendateien sichern

Die Audiodateien liegen im `storage`-Volume unter `storage/app/private/tenants/`. Sie
ändern sich selten und wachsen stetig, deshalb passt hier ein **inkrementeller Sync**
besser als ein Archiv. Ein Beispiel mit `rsync` auf einen anderen Rechner:

```sh
# Volume-Pfad auf dem Host ermitteln
docker volume inspect radioring_storage --format '{{ .Mountpoint }}'

# Täglich per Cron, nur Geändertes:
rsync -a --delete \
    /var/lib/docker/volumes/radioring_storage/_data/app/private/tenants/ \
    backup@example.org:/srv/radioring-media/
```

Hinweise dazu:

- **Nicht packen.** MP3, AAC und FLAC sind bereits komprimiert; ein `tar.gz` kostet volle
  CPU-Last und spart ein bis zwei Prozent.
- Der Ordner `storage/app/private/chunks` enthält nur angefangene Uploads und kann weg
  bleiben.
- Datenbank und Medien sollten **zeitnah zueinander** gesichert werden. Eine Datei, die in
  der Datenbank steht, aber in der Mediensicherung fehlt, fällt erst beim Ausspielen auf.
- Wer keinen Zugriff auf den Host hat, kommt auch so an die Dateien:
  `docker compose cp app:/app/storage/app/private/tenants ./media`.

Zurückspielen: die Dateien an dieselbe Stelle im `storage`-Volume legen, mit denselben
relativen Pfaden. Die Datenbank verweist auf genau diese Pfade.

---

## 9. Fehlersuche

### Die Station sendet Stille

- Laufen **Scheduler und Queue-Worker**? Ohne sie entstehen keine Rundowns.
- Gibt es für die **aktuelle Stunde** einen Rundown mit Status `ready`?
- Ist ein **Ausgang** aktiv und der Container gestartet?
- `php artisan radioring:schedule-status {station}` zeigt Cursor und Rundown.

### Der Container startet nicht, das Dashboard bleibt auf „starting"

- Läuft der **Queue-Worker**? Der Start ist ein Job.
- `docker compose logs app` zeigt jetzt die Fehlermeldung von Docker selbst, etwa
  `network radioring not found` oder `manifest unknown`.
- Existiert `STATION_IMAGE`, und sind bei privater Registry die `STATION_REGISTRY_*`
  gesetzt?
- Ist überhaupt ein Container-Treiber konfiguriert? Das Dashboard sagt es, wenn nicht.

### Medien-Upload scheitert mit 502 oder 413

Uploads laufen in 4-MB-Chunks und werden serverseitig zusammengesetzt. Von außen nach
innen prüfen:

1. **Der Reverse Proxy.** Häufigste Ursache. nginx steht per Vorgabe auf
   `client_max_body_size 1M`, also unterhalb der Chunk-Größe; mindestens `32M` setzen.
   Traefik hat von Haus aus kein Body-Limit.
2. **PHP-Grenzen.** In `docker/php.ini` auf 512 MB gesetzt. Prüfen mit
   `docker compose exec app php -i | grep post_max_size`.
3. **Ist `storage/` beschreibbar** für den Container-Nutzer?

Die Chunk-Größe steht in `resources/views/livewire/media-library/index.blade.php`
(`CHUNK_SIZE`). Kleinere Chunks überstehen knappere Proxy-Grenzen, kosten aber mehr
Requests.

### Skip bewirkt nichts

App und Container müssen dieselbe Redis-Instanz und denselben Kanal nutzen. Die App
protokolliert, wie viele Abonnenten einen Befehl erhalten haben; null bedeutet, dass der
Container nicht auf dem Kanal lauscht, den du vermutest.

### Der Live-Eingang ist nicht erreichbar

Der Portbereich muss in der Firewall offen sein und `*.STREAM_DOMAIN` auf den Server
zeigen. Icecast-Source ist Klartext und umgeht den Reverse Proxy vollständig, ein
funktionierendes Panel sagt also nichts über einen funktionierenden Live-Eingang aus.

---

## 10. Lokaler Test ohne Server

Voraussetzung: Docker Desktop läuft.

```powershell
./docker/run-local.ps1
```

Führt `prepare-local-stream` aus, startet bei Bedarf `php artisan serve` und fährt dann
Icecast plus einen Station-Container hoch.

- App auf Port **8000**
- Hören unter **http://localhost:8010/{slug}**, etwa in VLC

Für den Weg von Hand siehe die Kommentare in `docker/docker-compose.local.yml`.
