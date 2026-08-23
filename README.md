# RadioRing

Self-hosted radio automation: media library, playlists, a weekly schedule, and one
Liquidsoap process per station that actually puts the programme on air.

You plan what should be playing when. RadioRing turns that into an hour-by-hour rundown,
hands it to Liquidsoap track by track, and streams the result to Icecast or laut.fm.

[![tests](https://github.com/RadioRing/radioring/actions/workflows/tests.yml/badge.svg)](https://github.com/RadioRing/radioring/actions/workflows/tests.yml)
[![linter](https://github.com/RadioRing/radioring/actions/workflows/lint.yml/badge.svg)](https://github.com/RadioRing/radioring/actions/workflows/lint.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> **Note on language.** The user interface and the end-user manual
> ([`docs/de/handbuch.md`](docs/de/handbuch.md)) are German. Code, comments and the operator documentation
> are English. Translations live in `lang/`; `APP_LOCALE=en` gives you an English UI for
> everything that has already been converted.

---

## What it does

- **Media library** with ID3 import, editable artist/title/album, tags, duplicate
  detection and offline loudness measurement (EBU R128) so every track goes out at a
  consistent level.
- **Playlists** as reusable building blocks. Besides fixed tracks they hold *fill* blocks
  (rotation-aware auto-fill up to a duration), *random* picks, external HTTP sources and
  ad-break markers.
- **Weekly grid**, 7 days by 24 hours. Each slot points at a playlist.
- **Rundowns**: the concrete, frozen playlist for one specific hour on one specific day,
  generated ahead of time from slot plus playlist. Hard starts cut the previous hour off on
  time; soft starts let it finish.
- **External sources** for dynamic content such as news, weather or syndicated shows.
  They are fetched, cached and normalised shortly before airtime, with a live fallback.
- **Live input** per station over `input.harbor`, so a presenter can take over the stream
  from an encoder.
- **Outputs** to Icecast or laut.fm, optionally through Thimeo Stereo Tool.
- **Dashboard** with start/stop/restart, skip, current track and the next items.
- **Multi-station**: several stations share one media library and one team.

## How it fits together

RadioRing does not push audio. Each station runs its own Liquidsoap container that **pulls**
the next track from the API when it needs one. That keeps the application stateless with
respect to playback, and a restart of the panel never interrupts a stream.

```
   Browser
      │
      ▼
┌───────────────┐   Docker API    ┌──────────────────────────┐
│  RadioRing    │ ──────────────► │  Liquidsoap container    │
│  app          │  start / stop   │  one per station         │
│               │                 │                          │
│               │ ◄────────────── │  GET /script   (on boot) │
│  web + queue  │   pull model    │  GET /next     (per track)│
│  + scheduler  │ ──────────────► │  POST /now-playing        │
└───────────────┘                 └──────────────────────────┘
                                              │
                                              ▼
                                    Icecast / laut.fm
```

The data flow inside the app:

```
Media  ─┐
        ├─►  Playlist  ─►  Weekly slot  ─►  Rundown  ─►  Container  ─►  Output
Tags  ──┘
```

## Requirements

- A Linux host with Docker and Docker Compose v2. A small VPS is enough to start;
  each running station costs roughly one CPU core under load.
- A domain for the panel. For live input additionally a wildcard record and an open TCP
  port range.
- MySQL and Redis. The installer can bring both along or connect to existing ones.

Images are published for `linux/amd64` and `linux/arm64`.

## Installation

```sh
curl -fsSL https://raw.githubusercontent.com/radioring/radioring/main/install.sh | sh
```

The installer asks for the panel domain, whether to bring along MySQL, Redis and Traefik,
and how to reach Docker. It writes `/opt/radioring/.env` and a `docker-compose.yml`, pulls
the images, starts everything and prints an invite code for the first registration.

Skip the invite and get a ready administrator account instead:

```sh
curl -fsSL https://raw.githubusercontent.com/radioring/radioring/main/install.sh \
  | sh -s -- --admin=you@example.com
```

The generated password is printed once, at the end of the run.

### Releases and channels

An installation follows one of two channels, recorded in its `.env`:

| Channel | What it installs |
|---|---|
| `stable` (default) | The newest published release. The app image, the station image and the compose template are all pinned to that one git tag. |
| `edge` | The tip of `main`, rebuilt on every push. This is what the public demo runs. |

Nothing moves on its own. A pinned installation keeps running the version it was installed
with across reboots and container recreates, and only `update.sh` changes that:

```sh
cd /opt/radioring
./update.sh --check          # what would change
./update.sh                  # move to the newest release in the channel
./update.sh --version=v1.2.0 # move to an exact release
./update.sh --channel=edge   # switch channels
```

Updating rewrites the image pins and refetches the compose template from the matching tag,
so the three never drift apart. A jump across a major version stops and points at the
release notes; `--force` proceeds.

To install a specific version, or to track `main` deliberately:

```sh
... | sh -s -- --version=v1.2.0
... | sh -s -- --channel=edge
```

Prefer to do it by hand? [`docs/en/operations.md`](docs/en/operations.md) documents every variable, and
`docker/templates/docker-compose.yml` is the file the installer writes.

## Operating modes

| Mode | For |
|---|---|
| `standalone` | One installation, one account, one shared media library. Invited users join it. No station quota, no impersonation, no account bans. |
| `cloud` | Many independent tenants. Every registration opens its own tenant with its own library. |

`standalone` is the default. The mode is stored in the database and can be switched at
runtime under **Admin → Instance settings**, without redeploying.

## Security

**RadioRing controls Docker on its host in order to run the station containers. A remote
code execution bug in the application therefore means root on the host.** The bundled
socket proxy narrows the attack surface but is not a security boundary.

Please read [`SECURITY.md`](SECURITY.md) before putting an instance on the internet. It
also describes how to report a vulnerability privately.

## Development

```sh
git clone https://github.com/radioring/radioring.git
cd radioring
composer setup     # dependencies, .env, key, migrations, assets
composer dev       # server, queue worker, vite
```

Tests and code style:

```sh
php artisan test
vendor/bin/pint
```

For a local station container without a full deployment, see `docker/run-local.ps1` and
`docker/docker-compose.local.yml`. They start Icecast plus one station container against
your development server.

Conventions for contributions are in [`CONTRIBUTING.md`](CONTRIBUTING.md), including the
language rule and the typography rule (no em dashes, no ellipsis characters) which
`tests/Feature/TypographyTest.php` enforces.

## Documentation

| Document | Audience |
|---|---|
| [`docs/de/handbuch.md`](docs/de/handbuch.md) | End users, German. Authoritative. |
| [`docs/en/handbook.md`](docs/en/handbook.md) | The same, translated. |
| [`docs/en/operations.md`](docs/en/operations.md) | Operators. Variables, containers, control commands. |
| [`docs/de/betrieb.md`](docs/de/betrieb.md) | The same, German. Authoritative. |
| [`docs/architecture.md`](docs/architecture.md) | Contributors. Why the playout path looks the way it does. |
| [`SECURITY.md`](SECURITY.md) | Threat model, hardening, reporting. |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contributors. |

## Built with

Laravel 13, Livewire 4, Bootstrap 5, Pest 4, FrankenPHP and
[Liquidsoap](https://www.liquidsoap.info/) 2.2, which does the actual audio work.

## How this was built

RadioRing was written with the aid of AI coding assistants. Design decisions, the
architecture and every line that reached `main` were reviewed by a human being.

We say this because you should know what you are reading before you trust it in
production. The same rule applies to contributions: see
[`CONTRIBUTING.md`](CONTRIBUTING.md#ai-assisted-contributions).

## Support and Chat

We have an IRC channel on the ST-City Network (irc.st-city.net) #radioring. Feel free to
join and ask questions.

Use your favorite IRC client or a web client like [ST-City](https://st-city.net/app?channel=radioring).

Support can however only be done at best-effort basis.

## Licence

[GNU Affero General Public License v3.0 or later](LICENSE).

The AGPL matters here: if you run a modified RadioRing as a network service, you have to
offer your users the modified source. Running it unmodified for your own station places no
such obligation on you.
