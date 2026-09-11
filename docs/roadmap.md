# Roadmap

What RadioRing still needs before 1.0, and roughly in which order.

**None of this is set in stone.** Items move between versions, get split, get merged, or get
dropped. A real station running on RadioRing outranks anything written here.

Disagree with a priority, or need something that is not listed? Open an issue. That kind of
input has reordered this document before.

[`CHANGELOG.md`](../CHANGELOG.md) records what actually shipped.

## 0.5.0 - Ready for a real station

The difference between "works" and "can be left alone overnight".

- **An emergency loop.** The station script ends on `fallback([live, program, blank()])`.
  During a deploy, a database outage or a failing `/next`, the station sends silence. Every
  station gets an emergency playlist held inside the container. The underrun watchdog exists
  already; it has nothing to play.
- **Alerting.** Underruns, dropped streams, missing rundowns and stalled queue workers go to
  the protocol and the dashboard, where nobody looks at three in the morning. Mail and
  webhook notifications per station.
- **Airplay export.** GEMA and GVL reporting needs a CSV/XML export over a date range, plus
  ISRC, label, composer and publisher on the media file. Live shows are already logged:
  encoder metadata lands in the protocol with `source = live`.
- **Writing metadata back into the file.** Panel edits live in the database only; the file
  keeps the tags it was uploaded with. Saving a media file writes them back. The getid3
  writer is already vendored. This is what lets the new reporting fields, and later the cue
  points, travel with the audio.
- **Authorization cleanup.** Only station settings are owner-only
  (`Station::canBeManagedBy()`). Everything else treats owner and editor alike. Needs real
  policies, plus a third role for presenters who may go live but not rebuild the programme.

## 0.6.0 - Sound

What a station coming from mAirList or RadioDJ notices in the first hour on air. Changes how
it sounds, not what is played.

- **Cue points and transitions.** A media file carries one `fade_in` boolean. No cue-in, no
  cue-out, no intro ramp, no mix point, no overlap between tracks. Loudness analysis already
  runs ffmpeg on upload, so the markers can be measured in the same pass.
- **A waveform for correcting them.** Cue points found by analysis are a starting point, not
  the truth, and setting them by typing seconds is guesswork. The same ffmpeg pass writes a
  small peak file, a few kilobytes of JSON, which the editor draws and the operator drags
  the markers on. Peaks are computed on the server: making the browser load and analyse the
  mp3 would mean megabytes per click.
- **A voice track element.** A new element type. Ordinary mp3s, but marked as such, so a
  rundown shows where a presenter speaks. Depends on nothing else here and may arrive
  earlier.

## 0.7.0 - Programme planning

Deciding what runs when, beyond "this playlist on this weekday at this hour".

- **Time-based validity for elements.** A Christmas jingle in December, a "good morning"
  jingle on weekday mornings, a trailer that expires at eight tonight. A date range plus
  weekday and time-of-day windows on the media file, checked against the broadcast time when
  the rundown is generated. An element outside its window is dropped and noted in the
  protocol, so it is visible rather than mysterious. Built as a general mechanism: an ad
  campaign's run time is the same problem.
- **A special grid for individual dates.** Christmas Eve and New Year's Eve do not run the
  ordinary week. A date gets its own slots, which beat the weekly grid hour by hour; hours
  without a special slot keep inheriting. So "normal until seven, different from eight" is
  two entries, not a whole day. Concrete dates plus a copy function, not yearly recurrence:
  the 24th of December is a Tuesday one year and a Saturday the next, and only the station
  can say which grid wins.
- **Pulling affected rundowns forward.** Rundowns are generated ahead and frozen, so a
  window or a special day entered after the nightly run never reaches the day it was meant
  for. Changing either regenerates the future rundowns it touches and leaves played ones
  alone. Both features need it.
- **Fill by category, not just by tag.** A fill element knows tags and a duration. A music
  clock thinks in quotas: so much A rotation, so much B, so much C, weighted by time of day.
  The rotation planner stays as it is and gets better input.
- **Filling an hour to length.** Fill stops at its maximum duration and does not try to land
  on the hour. With a hard start, every overrun means a track cut off.

## 0.8.0 - Advertising

The `adbreak` element is a signal to laut.fm and nothing else. Selling airtime needs
campaigns with a run time, spots per day, rotation inside the break, and proof of broadcast.
Possibly two releases, in which case everything below moves down a number.

## 0.9.0 - Reachable from outside

- **A read-only catalogue API for the media library.** Search over title, artist, type and
  tags, with signed download URLs. The signing exists already: it is how Liquidsoap is served
  its audio.

  This has a purpose beyond our own use. A station that runs RadioRing on the server and goes
  live from a desktop automation loses the shared music database it had while both halves
  came from one vendor. We will not reimplement a proprietary database protocol. We publish
  one documented, open interface instead: **any playout software is welcome to speak it.**
  mAirList, RadioBOSS, SAM, anyone. The specification is public and we are happy to help.
- **A public now-playing endpoint** a station website or app can poll. Metadata reaches the
  panel today and stops there.

## 0.10.0 - Looking back

Everything above is about getting a programme on air. This is about what happened after.

- **Listener statistics over time.** Icecast is polled live and cached for ten seconds,
  nothing is kept. A station sees the current listeners and never the week.
- **Recording the programme.** For catching up, for checking a complaint, for cutting a
  podcast out of a show. Needs disk planning, a retention policy and somewhere to listen
  back.

## 1.0.0 - Trustworthy

- Some kind of security review, and rate limits beyond the login: uploads, invite redemption,
  the playout API.
- Media backups. Configuration backups exist, the audio does not.
- A documented and regularly tested restore.
- A load test of the playout API with a realistic number of stations.
- A stated upgrade guarantee, so an operator knows what an update costs.

## Considered and dropped

Kept so the discussion does not happen twice.

- **A cartwall in the panel.** Anyone going live connects through the harbor input with a
  real encoder, and the jingle pad sits in that software already. A cartwall in the browser
  would be a second audio path beside the encoder, with its own latency and monitoring
  problems, spread across two windows.
- **Placing voice tracks automatically.** Starting a voice track over the outro of one track
  and ending it under the intro of the next means overlaying two sources. The pull model
  hands out one item at a time, so this reaches into the playout path. The element type in
  0.6.0 stays; the automatic placement does not.
- **Recording voice tracks in the browser.** Presenters have a setup and cut in their own
  editor. Can be added later if anyone asks.
- **RadioRing as a desktop application.** Local playout with a sound card and a microphone is
  what mAirList and its kind are for. RadioRing is the server half, for the hours nobody is
  live. A desktop build would be a second product with a diverging playout path.

## Open questions

Questions we have not answered, not ideas we parked.

- **A local mirror agent.** If no playout software picks up the catalogue API, a small
  Windows tool could mirror part of the library into a local folder and keep it current. It
  would also let a live show carry on when the studio loses its line. Whether it is worth
  maintaining depends on whether the open interface finds takers, so it waits for that
  answer. If it is built, the direction stays one way: RadioRing is the source, the local
  copy a mirror, nothing syncs back. The moment both ends may write, this becomes a conflict
  resolution project.

## Not scheduled

A listener request system, a station-facing API for scheduling from outside, silence
detection on the output rather than the input, multi-language support beyond German and
English.

**Metering for the processed signal.** A station using Stereo Tool sets a licence key and a
preset and then hears the result without ever seeing it. Two ways to change that, and we do
not know yet whether the first one is possible: read state out of Stereo Tool itself, which
depends on what the Liquidsoap operator exposes of `libStereoTool.so` and on what Thimeo
allows for a proprietary library. Or measure the output instead: levels, loudness,
correlation, a rough spectrum, taken on the Liquidsoap side after processing. The second
answers most of what an operator actually asks (is it too loud, does it clip, is the phase
broken) and depends on nobody.
