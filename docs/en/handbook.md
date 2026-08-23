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
| **Media library** | The pool of music and jingles. It belongs to your **account**, not to a single station, so all your stations draw from the same library. |
| **Playlist** | A reusable list of building blocks, for example "Morning Pop", that you hang into the weekly grid. |
| **Weekly grid** | A plan of 7 days by 24 hours. Each hourly slot gets a playlist. |
| **Rundown** | The concrete list for one specific hour on one specific day, rolled out from the slot and its playlist, then frozen. |
| **Output** | Where the stream goes: an Icecast server or laut.fm. |
| **Container** | The Liquidsoap process running per station that actually produces the stream. |

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
4. Before saving you can check **title**, **artist** and **type** (music or jingle) per
   file. Title and artist are prefilled from ID3 tags where present.
5. **Save** adds them to the library.

After the upload, the **loudness (LUFS)** of each file is measured once in the background
and used to level playout. This happens automatically.

### Editing metadata

The edit icon of a file lets you change **title**, **artist** and **album**. There is also
a **fade-in** option: when active, the file is faded in gently at the start, which helps
with recordings that begin abruptly.

### Tags

Tags are free-form labels, for example *summer*, *calm*, *90s*, *station ID*, that group
your music. They are the basis for **random** and **fill** elements in playlists.

- **Manage tags**: create and delete tags.
- Assign tags to a file through its tag icon.
- **Bulk selection**: select several files and add or remove a tag at once. "Select all
  visible" helps.

Tags belong to the account as well, so a tag created in one station is available in all of
them.

### Filtering and searching

Narrow the list by **type** (music or jingle), by **tag** (or "untagged"), and by free-text
search over title and artist.

### Finding duplicates

The **duplicates** filter shows only files that appear more than once by normalised
*artist and title*, which is handy for cleaning up accidental double uploads. Duplicates
are listed next to each other.

Because the library is shared, this also finds the same track uploaded through two
different stations.

### Who may change what

| Role | Library |
|---|---|
| **Owner** | Upload, edit, tag, delete |
| **Editor** | Upload, edit, tag. **Not** delete. |

Deleting removes a file from every station of the account, which is why it stays with the
owner.

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
- **Start mode**:
  - **Soft**: seamless transition from the previous programme.
  - **Hard**: starts exactly on the hour; the running track is cut cleanly. Ideal for news
    and anything else that must begin on time.

> The start mode belongs to the **playlist**, not to the grid slot. Set it once on the
> playlist and it applies everywhere the playlist is used.

### Adding elements

| Type | Description |
|---|---|
| **From library** | A specific track or jingle, with search. |
| **Upload file** | Upload directly; the file also lands in the library. |
| **Random element** | **One** random track is drawn when the rundown is generated, optionally restricted to tags. |
| **Fill with music** | Fills the hour with random music, optionally by tag, up to a **maximum duration**. Good for hours without a fixed script. |
| **URL / stream** | An external audio file or stream by URL, with an optional duration. |
| **External source** | A previously defined dynamic source such as news or weather, see [External sources](#5-external-sources). |
| **Ad break** | A marker for a laut.fm ad break. |

### Order, offsets and editing

- **Sorting**: drag and drop.
- **Relative offset (MM:SS)**: an optional time offset within the hour, for example "this
  piece should run around 30:00". Accepts MM:SS or plain seconds.
- **Editing fill and random**: tags and, for fill elements, the maximum duration
  (60 to 7200 seconds) can be changed afterwards.

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

You can filter by **date**, **event type** and free text over title and artist. This is
useful both for reporting obligations and for answering "what was on at 2 pm yesterday".

---

## 10. Team and station settings

Under **Edit station**, available to the owner:

- **Name** of the station
- **Status**: active or paused
- **Regenerate rundowns nightly**
- **Team**: add users by **email address** as **editors**
- **Delete station**: irreversible

**Roles:**

- **Owner**: full access including settings, team and deletion.
- **Editor**: may maintain media, playlists, the grid, rundowns and outputs, but not delete
  media or manage the station.

> Note on the shared library: inviting someone as an editor into one station also gives
> them access to the media library of your **account**, including material of your other
> stations. Editors cannot delete media.

---

## 11. Administration

Visible only to administrators.

- **Users**: view and manage accounts.
- **Invite codes**: create one-time codes for registration. Without a valid code nobody can
  register.
- **Instance settings**: switch the operating mode between *standalone* and *cloud*. The
  change applies immediately, without a redeployment.

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
2. Create a playlist "News" containing that element, with **start mode hard**.
3. Place that playlist on the relevant hourly slots.
4. Generate rundowns.

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
drawn.

**I cannot create another station.**
Your station quota is used up. Ask an administrator. In standalone mode there is no quota.

**Where do I see what was played?**
In the **protocol**, filtered by date and event.

**How do I go live?**
Connect your encoder with the live credentials from the dashboard. The stream switches over
automatically and returns to the programme when you disconnect.

**A colleague sees media of my other station.**
That is intended. The media library belongs to the account, so every station of the account
shares it. Editors can use and add material but cannot delete it.
