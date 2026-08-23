# Architecture

Why RadioRing is built the way it is. Read this before changing anything in the playout
path; several decisions look odd until you know what they are avoiding.

## The pull model

RadioRing never pushes audio. Each station runs its own persistent Liquidsoap container
that **asks** for the next track when it needs one:

```
Track ends
  -> Liquidsoap calls GET /api/liquidsoap/{slug}/next
  -> RadioRing resolves: current time, matching rundown, next unplayed position
  -> responds with an annotated, signed URL
  -> Liquidsoap plays it, repeat
```

The alternative would be to generate a Liquidsoap playlist file and reload it on a
schedule. We do not, for three reasons:

- **No timing problems at the hour boundary.** A file-based schedule has to be rewritten at
  exactly the right moment, and rewriting it while Liquidsoap holds it open is a race.
- **Changes apply at the next track boundary, automatically.** Regenerating the 12:00
  rundown at 11:30 needs no reload and no signalling. The next `/next` call simply returns
  something different.
- **The running track is never interrupted.** Restarting the panel, editing a playlist or
  deploying a new version cannot cut into what is on air.

The cost is that RadioRing must answer `/next` quickly and reliably, and that the API is on
the critical path for playout. Hence the fallbacks described below.

## One container per station, persistent

A station's container is created once and stays up. It is not recreated per show.

On boot it fetches its own Liquidsoap script from
`GET /api/liquidsoap/{slug}/script`, generated per station from its outputs, its live input
and its Stereo Tool settings. That means a configuration change needs a container restart,
not a rebuild.

The container authenticates with the station API token, passed to it as an environment
variable and sent as an `Authorization: Bearer` header. The token is stored encrypted in
the database. Because it lives in the container's environment, rotating it requires
recreating the container, which is what `php artisan station:rotate-token` does.

## The prefetch cursor runs ahead of what is on air

This is the single most common source of bugs in this codebase.

Liquidsoap prefetches: `request.dynamic(prefetch=3)` asks for the next tracks *before* it
needs them, so the database cursor is several items ahead of what listeners hear.

Consequences you must respect:

- **Anything that reasons about "what is playing right now" has to read `now_playing`**,
  which the container reports back via `POST /now-playing`. The cursor is not the answer.
- **A skip cannot simply advance the cursor**, because the cursor already points past the
  current track. `LiquidsoapStateService::prepareSkip()` rewinds it first, then the app
  sends `flush_and_skip` so Liquidsoap drops its prefetched queue too.
- **A hard start** (an hour that must begin exactly on time) has to flush the queue for the
  same reason. A soft start lets the previous hour finish.

## Rundowns are frozen snapshots

A playlist is a reusable template. A rundown is the concrete, resolved list for one
specific hour on one specific day, generated ahead of time and then frozen.

Freezing matters because playlists contain non-deterministic elements: *fill* blocks pick
rotation-aware tracks up to a duration, *random* items pick one track. If those were
resolved at playout time, the protocol could not say what was actually played, and two
requests for the same position could disagree.

Rotation is planned in `MusicRotationPlanner` with a decaying penalty for tracks played
recently, rather than a hard block. If the library is too small, it fills anyway with the
least-penalised choice instead of failing.

## Media delivery

`/next` does not return a file path. It returns an annotated URL:

```
annotate:radioring_item_id="123",title="...",liq_amplify="-2.4 dB":safe:https://.../stream/media/demo/42?expires=...&signature=...
```

Three things are worth knowing:

- **The URL is signed, not token-authenticated.** Liquidsoap fetches it through
  `request.dynamic`, which resolves a bare URL and cannot set headers. Putting the API
  token in the query string would leak a full-access credential into every proxy log, so
  delivery URLs carry a short-lived signature scoped to that one file instead. Signatures
  are **relative**, so the same URL validates whether the container reaches the app
  internally or over the public host.
- **`safe:` is not decoration.** An uncaught error while resolving an HTTP request, for
  example `CURLE_RECV_ERROR`, can kill the whole Liquidsoap process. The protocol wrapper
  contains that failure to the single request.
- **Loudness is applied per track** through the `liq_amplify` annotation, computed from an
  offline EBU R128 measurement taken at upload time. It is deliberately *not* measured live
  by Liquidsoap's autocue: a corrupt MP3 crashed the entire process that way.

## Dynamic external sources

News, weather and syndicated shows are HTTP sources whose content changes. They are
prefetched shortly before airtime by `PrepareUpcomingHttpItemsJob`, normalised, and cached
locally.

At playout the app prefers the prepared copy. If preparation failed, the item is skipped
and the next one is fetched rather than handing an unresolvable URL to the container. This
keeps a failing third party from producing silence.

## The control path

Playout is pull-based, but the dashboard needs to push: skip, stop, restart. That travels
a longer road:

```
Dashboard -> Redis pub/sub -> container control loop -> telnet 127.0.0.1:1234 -> Liquidsoap
```

Redis rather than a direct connection, because the app must not need network access into
the containers. Two details bite:

- Redis pub/sub is **instance-wide**, not scoped to `REDIS_DB`. App and containers must
  share the same instance and channel, and every message carries the target container name
  so the others ignore it.
- The phpredis prefix (`OPT_PREFIX`) must be stripped when publishing, or the channel name
  silently gains a `laravel-database-` prefix that no container is listening on.

## Live input

Each station container runs an `input.harbor` so a presenter can take over the stream from
an encoder. Because Icecast source is plaintext and not HTTP, it cannot be routed by host
through a reverse proxy. Each station therefore gets its **own TCP port**, published
directly from the container to the host, and the encoder connects to
`{slug}.{STREAM_DOMAIN}:{port}`. The domain is only cosmetic; the port does the
distinguishing.

The Liquidsoap source is `fallback([live, program, blank()])`, so a connecting encoder
takes over and a disconnect falls back to the programme.

## Container control

The app talks to the Docker Engine API through `ContainerServiceInterface`. Two drivers
exist: `DockerService` (default, via a socket proxy or a mounted socket) and
`PortainerService` (legacy).

Two invariants:

- **The container spec is built entirely from server-side configuration.** No
  station-owned field ever reaches `HostConfig`, `Binds`, `Image` or `Privileged`. This is
  what keeps a compromised station from becoming host root. See `SECURITY.md`.
- **Routes must never be registered conditionally on the operating mode.** The mode is
  switchable at runtime while route definitions are cached at boot, so a mode-dependent
  route would be stale. Guard the behaviour in the controller instead.

## Tenancy

The media library belongs to a **tenant**, not to a station. Every station of a tenant sees
the same files, so a group of stations shares one music pool without linking anything.

Access is derived through the station, never through the user:

```
user -> station_users -> station -> tenant -> media_files
```

`users.tenant_id` exists, but it is only the *home* tenant, deciding where a user's new
stations and uploads land. Using it to decide what someone may see would let a user reach a
library they were never invited to.

In `standalone` mode there is exactly one tenant. In `cloud` mode there are many. The data
model is identical; only the row count differs.

## Application processes

The app image runs FrankenPHP as PID 1. With `APP_MODE=all` the queue worker and scheduler
run alongside it in restart loops rather than under a supervisor, so the container's
lifetime is tied to the web process.

The queue matters for playout: rundown generation, container starts, loudness analysis and
external prefetching all run there. A stalled queue does not stop the current programme,
but it will stop the next hour from being prepared.
