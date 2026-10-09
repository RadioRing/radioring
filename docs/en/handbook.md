# RadioRing: handbook

Welcome to RadioRing. With RadioRing you plan and run your own radio station: manage music
and jingles, build playlists, assemble a weekly schedule and put the finished stream on air
to Icecast or laut.fm, including live takeover from a microphone or encoder.

This handbook walks through the app in the order you actually use it: from the station via
media and playlists to scheduling and going on air.

> The German version, [`docs/de/handbuch.md`](../de/handbuch.md), is the authoritative one.
> This translation may lag behind.

---

## Contents

1. [Core concepts](#1-core-concepts)
2. [Getting started](#2-getting-started)
3. [Media library](#3-media-library)
4. [Playlists](#4-playlists)
5. [External sources](#5-external-sources)
6. [Scheduling: weekly grid and rundowns](#6-scheduling-weekly-grid-and-rundowns)
7. [Streaming: outputs and live input](#7-streaming-outputs-and-live-input)
8. [Dashboard: going on air](#8-dashboard-going-on-air)
9. [Protocol](#9-protocol)
10. [Team and station settings](#10-team-and-station-settings)
11. [Administration](#11-administration)
12. [Common workflows](#12-common-workflows)
13. [Troubleshooting and FAQ](#13-troubleshooting-and-faq)

---

## 1. Core concepts

A handful of terms run through the whole application:

| Term | Meaning |
|---|---|
| **Station** | Your radio station. Playlists, schedule and settings belong to it. |
| **Media library** | The pool of music, jingles and voice tracks. It belongs to your **account**, not to a single station, so all your stations draw from the same library. |
| **Playlist** | A reusable list of building blocks, for example "Morning Pop", that you hang into the weekly grid. |
| **Weekly grid** | A plan of 7 days by 24 hours. Each hourly slot gets a playlist. |
| **Rundown** | The concrete list for one specific hour on one specific day, rolled out from the slot and its playlist, then frozen. |
| **Output** | Where the stream goes: an Icecast server or laut.fm. |
| **Station container** | The Liquidsoap process running per station that actually produces the stream. The dashboard calls it "container". |
| **Container (playlist)** | A reusable block of elements, for example jingle and news, that you put into playlists. Unrelated to the station container. |

The rough data flow:

```
Media  ─┐
        ├─►  Playlist  ─►  Weekly slot  ─►  Rundown  ─►  Container  ─►  Output
Tags  ──┘
```

---

## 2. Getting started

### Signing up

RadioRing is a closed system: registration requires an **invite code**. If you do not have
one, ask an administrator.

In **Settings** you can optionally enable **two-factor authentication**.

### Creating or choosing a station

- On your first login without a station you land directly in **Create radio station**.
  Enter a name; the technical short name (slug) is derived from it automatically.
- If you have access to several stations, you get the **station picker**. The station you
  chose last stays active until you switch.
- A single station is selected automatically.

### Navigation

The sidebar is grouped into blocks:

- **Dashboard**: live status and control
- **Playlists**, **Media library**, **External sources**: content
- **Scheduling**: weekly grid, rundown
- **Streaming**: outputs, protocol
- **Administration** (admins only): users, invite codes, instance settings

---

## 3. Media library

The library is the pool that playlists and the random and fill mechanisms draw from.

**The library belongs to your account, not to one station.** If you run several stations,
they all see the same files. A track you upload through one station is immediately usable
in all of them, with nothing to link or copy.

### Uploading

1. Click **Upload**.
2. Drag your files into the upload area or select them. **MP3, M4A, OGG, WAV and FLAC** are
   supported.
3. Files are transferred in chunks, so large files and many files at once are fine.
4. Before saving you can check **title**, **artist**, **album** and **type** (music, jingle
   or voice track) per file. Title, artist and album are prefilled from ID3 tags where
   present.
5. Optionally pick **tags** that every file of this upload gets. A tag that does not exist
   yet can be created right in the form.
6. **Save** adds them to the library.

After the upload, the **loudness (LUFS)** of each file is measured once in the background
and used to level playout. This happens automatically.

### Media types

- **Music**: what fill elements fill the hour with.
- **Jingle**: station IDs, sweepers, trailers. Only plays where you place it in a playlist
  or where a random element picks it.
- **Voice track**: a pre-recorded link by a presenter. Technically an ordinary audio file,
  but marked in purple with a microphone in the rundown and on the dashboard, so you see
  where someone speaks. A voice track is recorded for one particular spot, so random
  elements never pick it: you place it in the playlist yourself.

### The file dialog

Clicking the title of a file (or its pencil icon) opens the dialog where everything about
that file is edited:

- **Title**, **artist**, **album** and **type**.
- **Fade in**: the file is faded in gently at the start, which helps with recordings that
  begin abruptly.
- **Notes** and **tags**.
- **Run time** and **airtime windows**, see below.
- **Replace file** (owners only): a new version takes the place of the old one. Playlists,
  tags and metadata stay as they are. Rundowns that are already generated play the old
  version until you regenerate them. Previous versions can be restored and are removed
  automatically once no rundown refers to them any more.

On saving, title, artist and album are also **written back into the file** (MP3, FLAC,
OGG, M4A), and so are the values from the upload form. The audio itself is not touched,
cover art and other tags stay. A file you download later or play in another program carries
the same details as the panel. WAV files are left alone.

### Run time and airtime windows

Both limit when **fill and random elements** may pick a file. Elements you place in a
playlist yourself always play.

- **Run time**: an optional start and an optional end, each with date and time. For
  example a Christmas jingle only from 1 to 26 December, or a trailer that expires at eight
  tonight. The start counts, the end does not. The library shows the run time as a badge
  and marks expired files in red.
- **Airtime windows**: weekdays plus a from/to time, several if needed. A window may run
  past midnight. For example a "good morning" jingle only on weekdays from 6 to 10.

If a file has both, the run time **and** one of the windows have to allow the moment. What
counts is the planned airtime, not the moment the rundown is generated.

### Tags

Tags are free-form labels, for example *summer*, *calm*, *90s*, *station ID*, that group
your music. They are the basis for **random** and **fill** elements in playlists.

- **Manage tags**: create, rename (pencil icon on the tag) and delete tags. Random and fill
  elements refer to tags internally, so a new name changes nothing about what they pick.
- Assign tags to a file in the file dialog, or already while uploading.
- **Bulk selection**: select several files and add or remove a tag at once. The selection
  carries across pages.

Tags belong to the account as well, so a tag created in one station is available in all of
them.

### Filtering and searching

Narrow the list by **type** (music, jingle, voice track), by **tag** (or "untagged"), and by
free-text search over title and artist.

The library shows **50 files per page**. Changing a filter or the search goes back to page
one. With several pages, **Select page** ticks only the current page; after that, **Select
all *n* matches** takes in every file of the filter for bulk tagging.

### Finding duplicates

The **duplicates** filter shows only files that appear more than once by normalised
*artist and title*, which is handy for cleaning up accidental double uploads. Duplicates
are listed next to each other.

Because the library is shared, this also finds the same track uploaded through two
different stations.

### Who may change what

| Role | Library |
|---|---|
| **Founder**, **owner** | Upload, edit, tag, replace, delete |
| **Editor** | Upload, edit, tag. **Not** replace or delete. |

Deleting and replacing reach every station of the account, which is why they stay with the
owners.

---

## 4. Playlists

A playlist is a reusable template. It is not broadcast directly; it is hung into the weekly
grid and rolled out there into an hourly rundown.

### Creating a playlist

Under **Playlists → New playlist** you set:

- **Name**
- **Playback mode**:
  - **Sequential**: elements in the given order.
  - **Random**: the order is shuffled when the rundown is generated.

Whether an hour starts on the second or something runs at a set minute is decided by
**fixed time elements** in the playlist, see below.

### Adding elements

The editor shows the playlist on the left and a **palette** on the right with four tabs:
**Media**, **Container**, **External** and **Special**. One search field covers all of them.

- **One click** appends an element.
- **Tick several** and insert them as a block, in the order you ticked them.
- **Drag** an element straight to the position where it belongs.
- On the *Media* tab, **Upload new** uploads a file directly; it also lands in the library.

| Type | Description |
|---|---|
| **File from the library** | A specific track, jingle or voice track. |
| **Random element** | **One** file is drawn when the rundown is generated, optionally restricted to tags. Files that have not played for a while come first. Voice tracks are never drawn. |
| **Fill with music** | Fills with music, optionally by tag, up to the next fixed time or the full hour, optionally capped by a **maximum duration**. The last tracks are picked so the music ends as close to that point as possible. |
| **URL / stream** | An external audio file or stream by URL, with an optional duration. |
| **External source** | A previously defined dynamic source such as news or weather, see [External sources](#5-external-sources). |
| **Container** | A reusable block of elements, see below. |
| **Fixed time** | Pins the element behind it to a minute of the hour, soft or hard, see below. |
| **Ad break** | A marker for a laut.fm ad break. |

Fill and random elements keep to the **rotation rules**: the GVL repeat rules first, then a
minimum gap before the same title returns, never the same artist twice in a row, the
station's [artist separation](#10-team-and-station-settings), and preferably not the same
title at the same time as yesterday. If the library is too small for that, the hour is
filled anyway and the protocol records it.

### Fixed times

A **fixed time** element pins the element right behind it to a minute of the hour, for
example 30:00.

- **Soft** (yellow): once the time is reached no further fill music starts. The running
  track plays out, then the element follows.
- **Hard** (red): the programme is faded out and cut, and the element starts on the
  second. For news on the hour, put a hard fixed time `00:00` at the top of the playlist.

Fill music in front of a fixed time plans exactly up to it. The editor warns when the
programme in front runs too long or leaves a gap before a hard cut.

### Containers

A container is a reusable block, for example jingle, news and ad break, that you put into
any number of playlists. Create it under **Playlists → New container** and edit it in the
same editor. It can hold every element type, fill and random included. When a rundown is
generated it is resolved into its elements in place. Containers do not go on the weekly
grid and do not nest.

### Order and editing

- **Sorting**: drag and drop.
- **Duplicating**: every element has a duplicate button that puts the copy right behind
  it. Ticked elements can be duplicated or removed together.
- **Length of the hour**: every element shows its start counted from the beginning of the
  playlist, and the header shows the total against the hour. Lengths that are only known
  at playout (fill, random) are marked as such.
- **Editing fill and random**: tags and, for fill elements, the maximum duration
  (60 to 7200 seconds) can be changed afterwards; for a fixed time, the minute (MM:SS) and
  soft or hard.

---

## 5. External sources

External sources are dynamic content fetched fresh shortly before airtime, such as news,
weather or syndicated pieces. You define them once as reusable entries and then use them as
a playlist element of type *external source*.

Under **External sources → New source**:

- **Name**: shown later as the playlist element.
- **Kind**: **URL** for a fixed audio address, or **news**, **weather**,
  **news and weather** for dynamically generated content.
- **Expected duration** (optional): a guide value for scheduling.
- **Prefetch** in seconds: how long **before** airtime the content is fetched and prepared.
  Default 180. Larger means more buffer but less current.
- **Freshness** in seconds: how long an already fetched item may be reused before being
  loaded again. Zero means fetch every time.
- **Normalise**: level the loudness. Recommended.
- **Trim leading silence**
- **Fade in**

Before airtime the content is downloaded, normalised and cached locally. If nothing is
ready in time, a fallback prevents a gap.

---

## 6. Scheduling: weekly grid and rundowns

This is where playlists become an actual broadcast schedule.

### Weekly grid

The grid is 7 weekdays by 24 hours. Each hourly slot can hold a playlist.

- **Fill a slot**: click it and assign a playlist.
- **Several slots at once**: select multiple cells and assign or clear together.
- Empty slots broadcast nothing scheduled in that hour.

Each slot also shows whether a **rundown** already exists for the upcoming broadcast.

### Generating rundowns

A rundown is the concrete, frozen list for *one hour on one date*. Random and fill elements
are only rolled out at generation time.

- **Single**: generate the rundown for the next matching broadcast right at the slot.
- **Several**: use the generate panel to select weekdays and generate all configured slots
  of those days at once. You get a report of *created, skipped, failed* per hour.
- **Nightly**: enable "regenerate rundowns nightly" in the
  [station settings](#10-team-and-station-settings).

### Rundown detail view

Opening a rundown lets you:

- **regenerate** it, as long as it has not been played,
- **remove** individual tracks,
- **replace** a track with another one from the library.

Every row shows the planned time and a badge for where it came from (template, fill, news,
ad break and so on). **Voice tracks** have a purple background and a microphone, so you see
at a glance where someone speaks. Fixed times show in their colour.

> A rundown is frozen. If you change a playlist, a fixed time, or a file's run time or
> airtime windows afterwards, it only applies to hours that are generated again.

> **Important:** tracks that have already been broadcast, **are currently playing, or have
> already been preloaded** are locked and cannot be changed. This keeps the stream from
> breaking under your hands. A fully played rundown cannot be regenerated.

---

## 7. Streaming: outputs and live input

### Outputs

An **output** is where your stream is sent. Under **Outputs** you create one or more:

- **Type**: **Icecast** or **laut.fm**
- **Host**, **port**, **mount point**
- **Username** (usually `source`) and **password**. The password is never displayed when
  editing; leaving it empty keeps it unchanged.
- **Bitrate**: 64 / 96 / 128 / 192 / 256 / 320 kbit/s
- **Active**: only active outputs are fed

> **After any change to outputs you have to restart the container** from the dashboard, so
> the new broadcast script is loaded. The app reminds you.

If your provider expects parameters on the mount point, for example `/station?prio=3`, enter
them as they are. RadioRing uses the plain mount name where credentials are required and
passes the full value to the stream.

### Live input

Every station has its own **live input** for taking over the running programme with an
encoder or microphone, for example BUTT, Mixxx or OBS.

The credentials are on the **dashboard**:

- **Host**: `{slug}.<stream domain>`
- **Port**: specific to the station
- **Mount point**: usually `/live`
- **Username**: `source`
- **Password**: generated per station

As soon as a live encoder connects, the stream switches to the live input; when it
disconnects, the scheduled programme continues. The current live status is shown on the
dashboard.

---

## 8. Dashboard: going on air

### Controlling the container

- **Start**: starts the station's Liquidsoap container and the stream goes on air.
- **Stop**: ends the container.
- **Restart**: reloads the broadcast script. Needed after changes to **outputs** and
  similar base settings.

> This requires container control to be configured on the server. If it is not, the
> dashboard says so instead of acting.

### Now playing

The dashboard shows the current track with artist, a progress bar and, where available, its
position in the rundown. During a live takeover the live status is shown instead.

Below it follow the next elements with their expected start. Voice tracks carry a purple
badge with a microphone there.

### Skipping a track

**Next track** skips the current title cleanly. RadioRing makes sure exactly one step is
taken, rather than several, even though tracks have already been preloaded internally.

---

## 9. Protocol

The **protocol** is the station's broadcast log, sorted by time:

- played **playlist tracks**
- **live tracks** during a takeover
- **live on and off** transitions
- **rundown generations**
- **underruns** (the rundown ran dry, the station sent silence)
- **missing external elements** (not prepared in time, skipped)
- **rotation rules**: a rundown breaks the GVL repeat rules, usually because the music
  pool is too small
- start and end of the **emergency loop**

You can filter by **date**, **event type** and free text over title and artist. This is
useful both for reporting obligations and for answering "what was on at 2 pm yesterday".

---

## 10. Team and station settings

Under **Edit station**, available to the owner:

- **Name** of the station
- **Status**: active or paused
- **Regenerate rundowns nightly**
- **Artist separation** (default 45 minutes): the minimum gap between two titles of the
  same artist in fill and random elements. The same artist never plays twice in a row
  anyway, and the GVL rules always apply. `0` turns the gap off.
- **Team**: add users by **email address**. New members are **editors**; the role can be
  switched between editor and owner at any time.
- **Delete station**: irreversible, founder only

**Roles:**

- **Founder**: whoever created the station. May do everything an owner may, and is the
  only one who can delete the station. The founder's role cannot be changed.
- **Owner**: full access including settings, team, and replacing and deleting media.
- **Editor**: may maintain media, playlists, the grid, rundowns and outputs, but not
  replace or delete media or manage the station.

> Note on the shared library: inviting someone as an editor into one station also gives
> them access to the media library of your **account**, including material of your other
> stations. Editors cannot delete media.

### Emergency loop

The station script plays a live takeover first, the programme second. If neither is
available, during an update, a database outage or an hour whose rundown ran out, the station
used to send silence. Pick a few files under **Edit station** and they play instead.

- The files are copied into the station container and play from there, so the loop keeps
  running even while RadioRing itself is unreachable.
- They are picked at random and repeat as long as needed. Their measured loudness is
  applied, so the loop is as loud as the programme.
- As soon as the programme is available again it takes over, cutting the emergency file off
  wherever it happens to be.
- The dashboard shows **EMERGENCY LOOP** while it is on air, and the protocol records the
  start and the return to the programme.
- The number of files and their total size are capped: the container has to hold them.
- Selecting or removing a file takes effect within a few seconds, without restarting the
  stream. The card says when the container last fetched the set.
- Nothing selected means the old behaviour: silence during a fault.

Anything works as content: a jingle, a spoken announcement, a music bed. Two or three files
are enough.

### Alert mails

The founder and every owner get a mail when the station

- sends silence,
- plays the emergency loop,
- is meant to run but plays nothing (container crashed or failed to start),
- has no rundown for the current hour.

A second mail follows once the problem is resolved. Problems shorter than two minutes are
not mailed. Editors get no alerts.

- Switch off for the station: **Edit station**, *Alert mails to the owners*.
- Switch off for yourself: **Settings -> Profile**, *Alert mails for my stations*.

Stopped or paused stations are not watched. Mails only arrive if an administrator has set
up a mail server (see section 11).

---

## 11. Administration

Visible only to administrators.

- **Users**: view and manage accounts.
- **Invite codes**: create one-time codes for registration. Without a valid code nobody can
  register.
- **Instance settings**: switch the operating mode between *standalone* and *cloud*. The
  change applies immediately, without a redeployment.
- **Outgoing mail** (in the instance settings): SMTP server, login and sender for alert
  mails and password resets. **Send a test mail** checks the values in the form before you
  save, and shows the error of the mail server if sending fails. While *Use this mail
  server* is off, the `MAIL_*` values from `.env` apply. *Send station mails with their own
  sender* sends alerts as `<slug>-noreply@<domain>` under the station name. Only switch it
  on if your mail server may send for every address of that domain.
- **Anonymous usage statistics** (in the instance settings): off by default. Once switched
  on, the instance sends size ranges per station (such as "11-100 media files") and yes/no
  details on features in use to radioring.de once a day, for example whether laut.fm, an
  external Icecast or Syndications4Radio is used. No names, URLs, content or credentials.
  *Show what would be sent* displays the report word for word beforehand. Switching it off
  deletes the random instance ID.
- **Backups**: secure the configuration and the database, manually or every night, with a
  retention limit and an optional passphrase. The archive can be downloaded here. Media
  files are deliberately not included. Restoring runs on the command line, see
  `docs/en/operations.md`, section 8.

**Update notice:** administrators see on the version badge in the sidebar when a new
release is out (on the `edge` channel: when `main` has new commits). Clicking it shows
what is new. The check can be switched off in `.env`, see `docs/en/operations.md`.

Which controls appear depends on the operating mode. In **standalone** mode, station quota,
impersonation and account bans are hidden, because a single-tenant installation does not
need them.

---

## 12. Common workflows

**Setting up a station from scratch**

1. Create the station.
2. Upload music and jingles to the **media library**.
3. Organise them roughly with **tags**.
4. Build one or more **playlists**, combining library, random, fill and jingle elements.
5. Place the playlists on hourly slots in the **weekly grid**.
6. Generate **rundowns** for the coming days.
7. Enter your Icecast or laut.fm target under **Outputs**.
8. Press **Start** on the **dashboard**.

**News exactly on the hour**

1. Create an external source of type *news*, or a URL source.
2. At the top of the playlist, add a **fixed time** element `00:00`, **hard**, and the news
   source behind it. If many playlists need this, put both into a **container**.
3. Generate rundowns.

**A Christmas jingle only in December**

1. Upload the jingle and tag it *Jingles*.
2. In the file dialog set its **run time** from 1 December to 27 December 0:00.
3. Use a **random element** with the tag *Jingles* in your playlists. Outside the run time
   it does not pick the Christmas jingle.

**A presented hour with voice tracks**

1. Upload the links as **voice tracks**.
2. Build the playlist: voice track, **fill with music**, **fixed time** (for example
   `15:00`, soft), the next voice track, and so on. The music plans itself up to the fixed
   time.
3. Use a playlist per show (or swap the voice tracks before generating), since voice
   tracks are recorded for one particular day.

**Applying a change to an output**

1. Edit or activate the output.
2. Dashboard → **Restart container**.

---

## 13. Troubleshooting and FAQ

**There is no sound, the stream is not running.**
Check on the dashboard whether the container is running. If not, press **Start**. Also
check that an **active output** with correct credentials exists.

**A change to an output has no effect.**
Outputs only take effect after a **container restart**, not automatically.

**One hour stays silent or empty.**
The hourly slot in the **weekly grid** is probably not filled, or no **rundown** was
generated. Fill the slot and generate the rundown.

**I cannot remove or replace a track in a rundown.**
It has already been played, is currently playing, or has been preloaded. Such tracks are
locked. Change later tracks instead.

**A random or fill element produces nothing.**
Make sure media files with the matching **tags** exist. Without matches, nothing can be
drawn. Also check the files' **run time** and **airtime windows**: files that have expired
or are blocked at that moment are passed over. Fill only takes music, random never takes
voice tracks.

**A change to a playlist, run time or airtime window has no effect.**
Rundowns that are already generated are frozen. Regenerate the hours concerned.

**I cannot create another station.**
Your station quota is used up. Ask an administrator. In standalone mode there is no quota.

**Where do I see what was played?**
In the **protocol**, filtered by date and event.

**How do I go live?**
Connect your encoder with the live credentials from the dashboard. The stream switches over
automatically and returns to the programme when you disconnect.

**I get no alert mails.**
Ask an administrator to send a test mail from the instance settings. Also check that alert
mails are on in the station settings and in your profile, and that you are an owner of the
station.

**A colleague sees media of my other station.**
That is intended. The media library belongs to the account, so every station of the account
shares it. Editors can use and add material but cannot delete it.
