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

[Unreleased]: https://github.com/RadioRing/radioring/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/RadioRing/radioring/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/RadioRing/radioring/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/RadioRing/radioring/releases/tag/v0.1.0
