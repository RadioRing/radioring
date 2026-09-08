# RadioRing: operations

Practical reference for installation, configuration and day-to-day operation.

For *why* the playout path looks the way it does, see
[`architecture.md`](../architecture.md). For the threat model, see
[`SECURITY.md`](../../SECURITY.md).

> The German version, [`docs/de/betrieb.md`](../de/betrieb.md), is the authoritative one.
> This translation may lag behind.

---

## 1. In one paragraph

The **Laravel app** manages stations, playlists and rundowns. For each station it starts a
persistent **Liquidsoap container** through the Docker API. That container pulls the next
track from the app over HTTP and streams the result to Icecast or laut.fm.

```
[ app container ]  ── Docker API ──▶  [ station container ]
  web/queue/sched                        │ fetches /script, /next, media over HTTPS
       ▲                                 ▼
       └──── /api/liquidsoap/{slug}/* ◀── streams ──▶ Icecast / laut.fm
```

---

## 2. Installation

The supported path is the installer:

```sh
curl -fsSL https://raw.githubusercontent.com/radioring/radioring/main/install.sh | sh
```

It asks for the panel domain, whether to bring along MySQL, Redis and Traefik, and how to
reach Docker. It then writes `/opt/radioring/.env` and a `docker-compose.yml`, starts
everything and prints an invite code for the first registration.

Non-interactive, for example in a provisioning script:

```sh
RR_APP_HOST=panel.example.com RR_DB=bundled RR_REDIS=bundled RR_PROXY=traefik \
  sh install.sh --yes
```

Every prompt can be preset with an `RR_`-prefixed environment variable. `--dir=` changes
the target directory.

Updating:

```sh
cd /opt/radioring && ./update.sh          # pull and restart
cd /opt/radioring && ./update.sh --check  # only report whether newer images exist
```

Running the installer again on an existing directory switches to repair mode: existing
values become the defaults, `APP_KEY` is never regenerated, and the previous `.env` is
backed up.

---

## 3. Configuration

The installer writes all of this. The tables are for when you edit by hand.

### 3.1 Application

| Variable | Example | Purpose |
|---|---|---|
| `APP_URL` | `https://panel.example.com` | Public URL |
| `APP_KEY` | `base64:...` | **Back this up.** See section 8. |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Always `false` in production: the debug screen renders every environment value, including passwords. |
| `APP_LOCALE` | `de` | UI language, `de` or `en` |
| `APP_MODE` | `all` | `all` = web, queue and scheduler in one container. Alternatively `web`, `queue`, `scheduler` to split them. |
| `RADIORING_MODE` | `standalone` | Initial operating mode only. The effective value lives in the database, see section 4. |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | | |
| `QUEUE_CONNECTION` | `database` | Rundowns and container starts run as jobs |

### 3.2 Container control

| Variable | Example | Purpose |
|---|---|---|
| `CONTAINER_DRIVER` | `docker` | `docker` or `portainer` (legacy) |
| `DOCKER_HOST` | `tcp://dockerproxy:2375` | Socket proxy (recommended) or `unix:///var/run/docker.sock` |
| `DOCKER_API_VERSION` | `v1.43` | Empty means the daemon default |
| `DOCKER_STATION_NETWORK` | `radioring` | Named network the station containers join. Empty means the default bridge, and then `LIQUIDSOAP_API_URL` must be publicly reachable. |
| `DOCKER_PULL_TIMEOUT` | `600` | The station image is several hundred megabytes; 30 seconds is not enough for a cold start. |
| `STATION_IMAGE` | `ghcr.io/radioring/liquidsoap-station:latest` | |
| `STATION_REGISTRY_USERNAME` / `_PASSWORD` | | Only for a private registry |

> **Anyone who can reach the Docker API is effectively root on the host.** The socket proxy
> narrows the attack surface, it is not a security boundary. Read `SECURITY.md`.

For the legacy driver: `PORTAINER_ENDPOINT`, `PORTAINER_TOKEN`, `PORTAINER_ENVIRONMENT`.

### 3.3 Playout and delivery

| Variable | Example | Purpose |
|---|---|---|
| `LIQUIDSOAP_API_URL` | `http://app:8080` | Base URL under which the container reaches the app. Empty means `APP_URL`. Locally `http://host.docker.internal:8000`. |
| `DELIVERY_URL_TTL_SECONDS` | `21600` | Lifetime of the signed media URLs. Generous on purpose: the prefetch cursor runs ahead, and a hard start can hold items back. Too short means silence on air. |
| `LOUDNESS_NORMALIZATION` | `true` | Offline EBU R128 measurement at upload time |
| `LOUDNESS_TARGET_LUFS` | `-14` | |

### 3.4 Control channel

Skip, stop and restart travel from the dashboard through Redis to the container.

| Variable | Example | Purpose |
|---|---|---|
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | | App side |
| `STATION_REDIS_HOST` | *(empty)* | Different address for the container, if it reaches Redis elsewhere |
| `LIQUIDSOAP_CONTROL_CHANNEL` | `radioring_station_control` | Must match on both sides |

App and containers must share the **same Redis instance** and channel. Redis pub/sub is
instance-wide; `REDIS_DB` only scopes keys, not channels.

### 3.5 Live input

| Variable | Example | Purpose |
|---|---|---|
| `STREAM_DOMAIN` | `stream.example.com` | Empty disables live input |
| `STREAM_PORT_MIN` / `STREAM_PORT_MAX` | `8001` / `8099` | One port per station, published on the host |
| `STREAM_MOUNT` / `STREAM_USERNAME` | `/live` / `source` | |

Requires a wildcard DNS record `*.stream.example.com` pointing at the server and the port
range open in the firewall.

---

## 4. Operating mode

| Mode | Behaviour |
|---|---|
| `standalone` | One tenant. Invited users join it. No station quota, no impersonation, no account bans. |
| `cloud` | Many tenants. Every registration opens its own with its own media library. |

The mode is stored in the database and switchable at runtime under
**Admin → Instance settings**, without redeploying. `RADIORING_MODE` only supplies the
initial value, because the entrypoint runs `config:cache` on every start.

---

## 5. Command reference

Inside a container: `docker compose exec -T app php artisan <command>`.

### User and access

```sh
php artisan user:manage {email} [--verify] [--admin] [--ban] [--quota=N] [--password=...]
php artisan invite:manage --create --count=1 --note="..."
php artisan invite:manage --list
```

Without options `user:manage` only prints an overview of the account.

### Stations

```sh
php artisan station:rotate-token {slug|id} [--force] [--no-restart]
```

Issues a new API token and recreates the container, because the token is passed to it as an
environment variable. Playout is interrupted briefly, so it asks first.

### Media

```sh
php artisan media:rescan-tags [--station=slug] [--force] [--dry-run]
php artisan media:measure-loudness [--station=slug]
php artisan media:prune-chunks [--hours=2]
```

### Backups

```sh
php artisan backup:run [--auto] [--passphrase=...] [--queue]
php artisan backup:restore {id|filename|path} [--passphrase=...] [--force]
```

`backup:run` writes a configuration backup to `storage/app/private/backups` and then
applies the configured retention. `--auto` is the nightly run: it uses the passphrase
stored in the panel. Details in [section 8](#8-backups).

### Diagnostics

```sh
php artisan radioring:schedule-status {station}   # cursor, now playing, current rundown
php artisan radioring:enforce-hard-starts         # normally run by the scheduler
```

### Local development

```sh
php artisan radioring:prepare-local-stream {station?} [--host=host.docker.internal] [--port=8000]
```

Configures a station for the local Docker test: creates an Icecast output, generates a
rundown for the current hour, resets the state and writes `docker/.env`.

---

## 6. Scheduled jobs

Registered in `routes/console.php`:

| When | Job | Purpose |
|---|---|---|
| daily 22:00 | `GenerateDailyRundownsJob` | 24 rundowns for the next day from the weekly grid |
| hourly at :55 | `PreloadNextRundownJob` | Make sure the next hour has a rundown |
| every minute | `radioring:enforce-hard-starts` | Cut over to an hour marked as a hard start |
| every minute | `PrepareUpcomingHttpItemsJob` | Prefetch external sources shortly before airtime |
| hourly | `media:prune-chunks` | Remove abandoned upload chunks |
| daily, configurable | `backup:run --auto` | Configuration backup, only when enabled in the panel |

**Without a running scheduler and queue worker no rundowns are created**, and the station
falls silent after the current hour. With `APP_MODE=all` both run inside the app container.
If you split them, add a cron entry:

```
* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Containers

### App container

FrankenPHP on port 8080, health check on `/up`. On start it runs `migrate --force`,
`storage:link` and the config, route, view and event caches.

With `APP_MODE=all`, FrankenPHP is PID 1 and the queue worker and scheduler run alongside
it in restart loops. If FrankenPHP dies, the container ends and Docker restarts it.

### Station containers

Not deployed by CI. They are created by the app when you press **Start** in the dashboard.
On boot the container fetches its `.liq` script from
`{LIQUIDSOAP_API_URL}/api/liquidsoap/{slug}/script`.

Configuration changes to outputs or the live input therefore need a **restart** of the
station container, not a redeploy of the app.

---

## 8. Backups

Two things, and both matter:

1. **Configuration and database.** Everything except the audio files. That is what the
   built-in backup feature covers, see below.
2. **`storage/`.** The audio files. Deliberately **not** part of the built-in backups: a
   media library is orders of magnitude larger than the rest and wants to be secured
   differently. Instructions further down.

`APP_KEY` encrypts station API tokens as well as output, live input and partner passwords.
**Without that key those values cannot be recovered from a database backup.** It is
therefore part of every configuration backup. If the key is lost and no backup exists, the
only way out is to rotate every station token with `station:rotate-token` and re-enter the
output passwords by hand.

### 8.1 Configuration backup

In the panel under **Administration -> Backups**. A backup holds a database dump, the
`.env` if the installation has one, and a manifest carrying `APP_KEY`. Together that is a
complete set of keys to the installation, so:

- An archive that leaves the server gets a **passphrase**. It is then encrypted with
  AES-256-GCM (`.zip.enc`). A lost passphrase cannot be recovered, and the archive is
  worthless without it.
- Downloads go to logged-in administrators only. There is deliberately no public link.

Automatic backups run nightly at the configured time, provided the **scheduler** is
running (see section 6). Retention deletes the oldest archives once the configured number
is reached; failed runs stay in the list, so a backup that has been broken for weeks is
visible.

On the command line:

```sh
docker compose exec -T app php artisan backup:run --passphrase='...'
```

The archives live inside the container under `storage/app/private/backups`, that is in the
`storage` volume. To get them out, use the panel or copy them:

```sh
docker compose cp app:/app/storage/app/private/backups ./backups
```

### 8.2 Restoring

A restore **replaces all data** of this installation and therefore only runs on the command
line, never from the panel.

```sh
# 1. Put the archive in place: either it already sits in the installation's backup
#    directory, or you copy it in.
docker compose cp ./radioring-config-2026-09-08_030000-ab12.zip.enc \
    app:/app/storage/app/private/backups/

# 2. Restore. The command first prints date, version and database driver of the
#    archive, and then asks.
docker compose exec app php artisan backup:restore \
    radioring-config-2026-09-08_030000-ab12.zip.enc --passphrase='...'

# 3. Restart the station containers so they pick up the restored configuration
#    (from the dashboard, or by restarting the stack).
```

Instead of the file name, the ID from the backup list or an absolute path works too.

What the command checks first, and why it may refuse:

- **Database driver.** A SQLite archive cannot be restored into a MySQL installation or the
  other way round. The dump carries the native schema of the source database.
- **`APP_KEY`.** If the key of this installation differs from the one in the archive, the
  command stops. The encrypted columns would be unreadable otherwise. The correct key is in
  the archive's `manifest.json` and in its `env` file. Put it into the `.env` next to the
  compose file, restart the stack and repeat the command. `--force` restores anyway, in
  which case station tokens and passwords have to be set again afterwards.

After a restore the backup list in the panel is empty: it points at archives on this host
and is therefore not part of a dump. The files in the backup directory stay where they are.

Onto a fresh host: run `install.sh` as usual, put the `APP_KEY` from the archive into the
`.env`, start the stack, copy the archive in, then `backup:restore`. The media files come
back separately (see below), otherwise every rundown points at files that are not there.

### 8.3 Backing up the media files

The audio files live in the `storage` volume under `storage/app/private/tenants/`. They
rarely change and keep growing, so an **incremental sync** fits better here than an
archive. An example with `rsync` to another machine:

```sh
# Find the volume path on the host
docker volume inspect radioring_storage --format '{{ .Mountpoint }}'

# Daily from cron, only what changed:
rsync -a --delete \
    /var/lib/docker/volumes/radioring_storage/_data/app/private/tenants/ \
    backup@example.org:/srv/radioring-media/
```

A few notes:

- **Do not compress.** MP3, AAC and FLAC are compressed already; a `tar.gz` costs full CPU
  load and saves one or two per cent.
- The `storage/app/private/chunks` directory only holds unfinished uploads and can be left
  out.
- Database and media should be secured **close together in time**. A file that is in the
  database but missing from the media backup only shows up when it is due to air.
- Without access to the host the files are still reachable:
  `docker compose cp app:/app/storage/app/private/tenants ./media`.

Restoring them: put the files back at the same place in the `storage` volume, with the same
relative paths. The database refers to exactly those paths.

---

## 9. Troubleshooting

### The station plays silence

- Are the **scheduler and queue worker** running? Without them no rundowns are generated.
- Is there a `ready` rundown for the **current hour**? Check the weekly grid.
- Is a **stream output** active and is the container running?
- `php artisan radioring:schedule-status {station}` shows cursor and current rundown.

### The container does not start, the dashboard stays on "starting"

- Is the **queue worker** running? Starting is a job.
- `docker compose logs app` now shows Docker's own error message, for example
  `network radioring not found` or `manifest unknown`.
- Does `STATION_IMAGE` exist, and are `STATION_REGISTRY_*` set for a private registry?
- Is the container driver configured at all? The dashboard says so if not.

### Media upload fails with 502 or 413

Uploads are chunked at 4 MB and assembled server side. Check from the outside in:

1. **The reverse proxy.** Most common cause. nginx defaults to `client_max_body_size 1M`,
   which is below the chunk size; set at least `32M`. Traefik has no body limit by default.
2. **PHP limits.** Set to 512 MB in `docker/php.ini`. Verify with
   `docker compose exec app php -i | grep post_max_size`.
3. **Is `storage/` writable** for the container user?

The chunk size lives in `resources/views/livewire/media-library/index.blade.php`
(`CHUNK_SIZE`). Smaller chunks survive tighter proxy limits at the cost of more requests.

### Skip does nothing

App and container must share the same Redis instance and channel. The app logs the number
of subscribers that received a command; zero means the container is not listening on the
channel you think it is.

### Live input is not reachable

The port range must be open in the firewall and `*.STREAM_DOMAIN` must resolve to the
server. Icecast source is plaintext and bypasses the reverse proxy entirely, so a working
panel says nothing about a working live input.

---

## 10. Local test without a server

Requires Docker Desktop.

```powershell
./docker/run-local.ps1
```

Runs `prepare-local-stream`, starts `php artisan serve` if needed, then brings up Icecast
plus one station container.

- App on port **8000**
- Listen on **http://localhost:8010/{slug}**, for example in VLC

See the comments in `docker/docker-compose.local.yml` to do it by hand.
