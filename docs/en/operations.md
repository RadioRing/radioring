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

Three things, and all three matter:

1. **The database.** Everything except the audio files.
2. **`storage/`.** The audio files. In the shipped compose file this is the `storage`
   volume.
3. **`APP_KEY`, separately from the database.** Station API tokens, output passwords, live
   input passwords and partner tokens are encrypted with it. **Without the key those values
   cannot be recovered from a database backup.**

If the key is lost, rotate every station token with `station:rotate-token` and re-enter the
output passwords by hand.

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
