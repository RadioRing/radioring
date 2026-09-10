# Changelog

Everything in here is written for the person who runs an installation and has to decide
whether to update today. Internal refactors, test changes and dependency bumps that change
nothing for an operator are left out on purpose.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the
version is below 1.0.0, a minor release may still change behaviour; the *Upgrade* section
of each release says what that means in practice.

The section for a released version is what the GitHub release page shows, so it is written
to stand on its own.

## [Unreleased]

## [0.4.0] - 2026-09-10

**New in this release:** Stereo Tool integration. Stereo Tool allows some low latency
sound processing on the server side, and is a great expansion to the sound quality of
your station.

### Added
- **External sources can be searched and added in batches to a playlist.** The editor only
  offered a single dropdown, which meant one trip through the form per element. There is now
  a search field and a list to tick off: the picked elements are added in one go, in the
  order they were picked, and an optional timestamp applies to the first of them.
- **Stereo Tool Integration from Thimeo.** Stations can now use stereo tool as a sound
  processor. The configuration is done in the station settings. Each station will require
  its own license key and a preset. Without preset the sound processing runs in default mode.

### Fixed

- **An unreachable external source is no longer asked again every minute.** A syndication
  or news download that failed was retried on every scheduler tick for the whole prefetch
  lead, which meant up to half an hour of requests per item against a partner that was
  already answering with an error. Failed attempts now back off (1, 2, 4, 8 ... up to 15
  minutes), and a successful download clears the counter. The last-second attempt when the
  item actually goes on air is unchanged, so nothing is lost from the programme.
- **The preparation of external content can no longer run twice at the same time.** While a
  download was still in progress, the next minute started a second run that fetched the
  same item again. Only one run is active at a time now.
- **A missing external element now shows up in the protocol.** If a syndication, news or
  weather element could not be downloaded, it was dropped from the programme silently: the
  error sat on the source in the library and nowhere else. The protocol now gets an entry
  with the reason at the moment the element would have gone on air, and it has its own
  filter entry.
- **Preparing external content puts less load on the database.** The minutely check loaded
  every upcoming external item of every station, including the next day's, only to discard
  almost all of them.
- **Silence after a rundown ran dry no longer lasts until someone hits skip.** When the
  hour was played out and the next one was not released yet, the player stopped asking for
  new tracks altogether: it kept sending silence even after the following rundown became
  available. A watchdog in the station script now wakes the request queue while the
  programme is off air.
- **A gap in the programme is now visible.** If nothing is available to play, the dashboard
  says so and the protocol gets an entry, instead of the player freezing on the last title.
  A stuck ad break in particular used to stay on the dashboard as if it were still running.
- **Playout catches up instead of drifting.** An hour that overran used to push the whole
  rest of the day back, and nothing ever pulled it forward again: a station could still be
  working off the 11:00 hour at 13:55. When a rundown is exhausted, playout now moves to
  the hour that is actually due and enters it at the position the clock calls for. Skipped
  hours are no longer aired late; every catch-up is recorded in the protocol.
- **Hard starts no longer begin in the middle of their hour.** If the programme was behind,
  a hard-start rundown took over the moment the previous track ended - the news could go on
  air at 12:57. A hard start is now enforced only around the top of the hour (a single
  track's overhang is still cut); later the catch-up above takes over.
- **A hard start that began too early is corrected at the top of the hour** instead of
  being treated as already done.
- **Music no longer breaks off mid-bar at a hard cut.** The running track is faded out
  over 0.8 seconds before the cut, then the next element (news with a time signal, for
  instance) starts hard and at full volume - which the fade-in on the incoming element
  could not achieve. The duration is configurable with `HARD_CUT_FADE_OUT_SECONDS`, and 0
  restores the previous immediate cut. Manual skips from the dashboard use the same fade.
  This change requires a restart of the station container.
- **The dashboard playlist no longer sticks to a dead now-playing report.** When the
  container stops reporting, the list falls back to the current hour instead of anchoring
  on the frozen track and projecting all air times into the past.

### Upgrade

```sh
cd /opt/radioring && ./update.sh
```

The container should run some migrations upon start automatically. No manual intervention
is required. If you want to run the migrations manually, you can do so with the following command:

```sh
php artisan migrate
```

***It is highly recommended*** to restart the streaming containers after the update. Use the stop and then play button
button in your dashboard to do so.

### Known limitations

- There is only one preset for Stereo Tool, additional ones can be uploaded manually or if you
  have any great .sts preset feel free to share them with us.
- The Stereo Tool integration has no UI yet.

## [0.3.0] - 2026-09-08

**New in this release: backups.** RadioRing can now secure its own configuration and
database. After updating, open **Administration -> Backups** once and switch the nightly
run on.

### Added

- **Backups** ([#1](https://github.com/RadioRing/radioring/issues/1)). Database, `.env` and
  `APP_KEY` are packed into one archive, manually or nightly at a configured time, with a
  retention limit and an optional passphrase (AES-256-GCM). Administrators download the
  archive from the panel; restoring runs on the command line with
  `php artisan backup:restore`. Media files are deliberately not part of it, section 8 of
  the operations manual describes an incremental sync for those.
- **FTP and FTPS for external sources.** Username and password live in their own encrypted
  fields instead of inside the URL, and spaces in an address are normalised automatically.
- **Duplicating an external source**, to build variants of an existing one faster.

### Fixed

- Hard starts could play the first item twice. Positioning inside the playout state machine
  is corrected along with it.
- External sources: normalisation and duration are now determined correctly for objects
  prepared inline.
- laut.fm news: the credentials of an output are recognised by being set, not by the output
  being enabled.
- Playout pointers carry unique identifiers, which makes organising playlists easier.
- The dashboard listed the live show twice. Its status badge now tells "on air - live",
  "no playout" and "offline" apart.
- Artisan commands aborted when Redis was unreachable, `key:generate` on a fresh
  installation included. Settings fall back to the database, and to their default when that
  is unavailable too.
- The certificate resolver of the public demo.

### Changed

- Traefik, Redis 8, Node 26 and the npm packages were updated.

### Upgrade

```sh
cd /opt/radioring && ./update.sh
```

Four additive migrations (three columns, one table) run when the container starts. No
manual step, and no interruption beyond the usual restart.

Recommended right after: switch the nightly backup on, set a passphrase, and download one
archive. It carries the `APP_KEY`, which is the only way to make the encrypted values of a
database backup readable again later.

### Known limitations

- The archives live in the `storage` volume, next to the media files. Against the loss of
  that volume, only a downloaded copy helps.
- No external target (FTP, SFTP, S3) yet, and no media backup from the panel. Both are
  planned for a later release.
- Without a running queue worker a manually started backup stays on "running".

## [0.2.0] - 2026-08-24

### Added

- **Internal Icecast.** One sidecar container per station, so a station can be broadcast
  without relying on an external provider.

### Changed

- The installer and the update script show the version number and handle prereleases.
- Traefik is enabled in the compose file of the public demo, which now follows the `edge`
  channel.

## [0.1.0] - 2026-08-23

First public release. Media library, playlists, weekly grid and rundowns, external sources,
live input, outputs to Icecast and laut.fm, per-station Liquidsoap containers, multi-tenant
and standalone operation, installer and update script.

[Unreleased]: https://github.com/RadioRing/radioring/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/RadioRing/radioring/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/RadioRing/radioring/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/RadioRing/radioring/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/RadioRing/radioring/releases/tag/v0.1.0
