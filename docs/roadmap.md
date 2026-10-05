# Roadmap

What RadioRing still needs, and roughly in which order. It is not tied to version numbers:
a release ships whatever is done. [`CHANGELOG.md`](../CHANGELOG.md) records what actually
shipped.

**None of this is set in stone.** Items move, get split, get merged, or get dropped. A real
station running on RadioRing outranks anything written here.

Disagree with a priority, or need something that is not listed? Open an issue. That kind of
input has reordered this document before.

## Next up

The only part with an order that holds. Reviewed with every release.

1. **Airplay export.** GEMA and GVL reporting needs a CSV/XML export over a date range, plus
   ISRC, label, composer and publisher on the media file. Live shows are already logged:
   encoder metadata lands in the protocol with `source = live`.
2. **Writing metadata back into the file.** Panel edits live in the database only; the file
   keeps the tags it was uploaded with. Saving a media file writes them back. The getid3
   writer is already vendored. This is what lets the reporting fields, and later the cue
   points, travel with the audio.
3. **Authorization cleanup.** Founder, owner and editor exist, but only station settings
   and media deletion check the role (`Station::canBeManagedBy()`). Needs real policies,
   plus a presenter role that may go live but not rebuild the programme.
4. **A date range for airtime windows.** Weekday and time windows exist. Missing: a
   Christmas jingle only in December, a trailer that expires at eight tonight. An optional
   from/to date on the file, checked in `MediaFile::isAirableAt()` and edited in the file
   dialog. The same mechanism later carries an ad campaign's run time.

## Planned

Grouped by topic, not by date. Within a group, the first item is the likeliest next.

### Running operation

- **Pulling affected rundowns forward.** Rundowns are generated ahead and frozen, so a
  change to a playlist, a fixed time or an airtime window never reaches hours that are
  already generated. Changing them regenerates the future rundowns they touch and leaves
  played ones alone. Special days (below) need it too.
- **Intervening in the running hour.** Watching something go wrong in the dashboard and
  having nothing to do about it but regenerate the rundown, which throws away the hour. An
  element should be draggable into the programme that is on air, right behind the track
  playing now. Liquidsoap has already resolved the next few requests, so an insert only
  becomes audible once that queue is dropped: `flush_and_skip` exists, but it cuts the
  running track as well, which is wrong here. An external element also has to be prepared
  on insert instead of by the minutely job.
- **More alert channels.** Alert mails exist. Missing: a webhook per station, and a
  heartbeat that notices stalled queue workers.

### Sound

What a station coming from mAirList or RadioDJ notices in the first hour on air.

- **Cue points and transitions.** A media file carries one `fade_in` boolean. No cue-in, no
  cue-out, no intro ramp, no mix point, no overlap between tracks. Loudness analysis already
  runs ffmpeg on upload, so the markers can be measured in the same pass.
- **A waveform for correcting them.** Cue points found by analysis are a starting point, not
  the truth. The same ffmpeg pass writes a small peak file, which the editor draws and the
  operator drags the markers on. Peaks are computed on the server: making the browser
  analyse the mp3 would mean megabytes per click.

### Programme planning

- **A special grid for individual dates.** Christmas Eve and New Year's Eve do not run the
  ordinary week. A date gets its own slots, which beat the weekly grid hour by hour; hours
  without a special slot keep inheriting. Concrete dates plus a copy function, not yearly
  recurrence: only the station can say which grid wins.
- **Fill by category, not just by tag.** A music clock thinks in quotas: so much A rotation,
  so much B, so much C, weighted by time of day. The rotation planner stays as it is and gets
  better input.

### Advertising

The `adbreak` element is a signal to laut.fm and nothing else. Selling airtime needs
campaigns with a run time, spots per day, rotation inside the break, and proof of broadcast.

### Reachable from outside

- **A read-only catalogue API for the media library.** Search over title, artist, type and
  tags, with signed download URLs. The signing exists already: it is how Liquidsoap is served
  its audio.

  A station that runs RadioRing on the server and goes live from a desktop automation loses
  the shared music database it had while both halves came from one vendor. We will not
  reimplement a proprietary database protocol. We publish one documented, open interface
  instead: **any playout software is welcome to speak it.** mAirList, RadioBOSS, SAM,
  anyone. The specification is public and we are happy to help.
- **A public now-playing endpoint** a station website or app can poll. Metadata reaches the
  panel today and stops there.

### Looking back

- **Listener statistics over time.** Icecast is polled live and cached for ten seconds,
  nothing is kept. A station sees the current listeners and never the week.
- **Recording the programme.** For catching up, for checking a complaint, for cutting a
  podcast out of a show. Needs disk planning, a retention policy and somewhere to listen
  back.

## Before 1.0

The one version number here, because it stands for a promise, not a date.

- Some kind of security review, and rate limits beyond the login: uploads, invite redemption,
  the playout API.
- Media backups. Configuration backups exist, the audio does not.
- A documented and regularly tested restore.
- A load test of the playout API with a realistic number of stations.
- A stated upgrade guarantee, so an operator knows what an update costs.

## Ideas, not planned yet

Worth doing, but nobody has committed to them.

- A listener request system, a station-facing API for scheduling from outside, silence
  detection on the output rather than the input, languages beyond German and English.
- **Metering for the processed signal.** A station using Stereo Tool hears the result
  without ever seeing it. Either read state out of Stereo Tool itself, which depends on what
  the Liquidsoap operator exposes and what Thimeo allows, or measure the output after
  processing: levels, loudness, correlation, a rough spectrum. The second answers most of
  what an operator asks (too loud, clipping, broken phase) and depends on nobody.
- **An assistant for programme planning.** A chat that answers questions about the library
  and the programme, such as "which tracks would fit a Christmas programme", "what ran too
  often last week", "which artist names are spelled two ways". It plans, it never changes
  anything itself. Three pieces, in this order:
  - Suggestions first, chat later. A question about the whole library is a batch job:
    artist, title, album and year go to a small model in chunks, and the answers land in a
    suggestions table (media file, add or remove, tag, confidence, reason). A review page
    lists them for the operator to accept or reject.
  - The chat on top works through read-only tools (search the library, list tags, media by
    tag, playlist summary, play history) and starts such jobs. It only names media it got
    from a tool. The tools check station access themselves; the prompt is not a permission
    layer.
  - Later, audio features (tempo, energy) measured by ffmpeg on upload, for questions
    metadata cannot answer.

  Off by default, bring your own API key per instance or tenant, provider interchangeable.
  Only metadata leaves the server, never audio. A usage limit per tenant keeps the cost
  predictable. Every suggestion carries a confidence, and "unknown" is a valid answer. Ties
  in with fill by category: suggested tags feed the same fill elements.

## Open questions

Questions we have not answered, not ideas we parked.

- **A local mirror agent.** If no playout software picks up the catalogue API, a small
  Windows tool could mirror part of the library into a local folder and keep it current. It
  would also let a live show carry on when the studio loses its line. Whether it is worth
  maintaining depends on whether the open interface finds takers. If it is built, the
  direction stays one way: RadioRing is the source, the local copy a mirror, nothing syncs
  back.

## Not coming

Kept so the discussion does not happen twice.

- **A cartwall in the panel.** Anyone going live connects through the harbor input with a
  real encoder, and the jingle pad sits in that software already. A cartwall in the browser
  would be a second audio path beside the encoder, with its own latency and monitoring
  problems.
- **Placing voice tracks automatically.** Starting a voice track over the outro of one track
  and ending it under the intro of the next means overlaying two sources. The pull model
  hands out one item at a time, so this reaches into the playout path. The voice track
  element stays; the automatic placement does not.
- **Recording voice tracks in the browser.** Presenters have a setup and cut in their own
  editor. Can be added later if anyone asks.
- **RadioRing as a desktop application.** Local playout with a sound card and a microphone is
  what mAirList and its kind are for. RadioRing is the server half, for the hours nobody is
  live. A desktop build would be a second product with a diverging playout path.
