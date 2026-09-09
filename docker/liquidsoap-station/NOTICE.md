# Bundled assets

## `ad_break.mp3`

A silent MP3 with no audio content. It carries no copyright and is not meant to be heard.

It exists only so that Liquidsoap has a file to annotate with `title="START_ADBREAK"`.
laut.fm reads that title from the stream metadata and starts its own ad break; the audio
itself is irrelevant, only the tag matters.

Mounted into the container at `/opt/ad_break.mp3`, configurable through
`ADBREAK_SIGNAL_PATH`.

## `stereotool/`

Thimeo Stereo Tool: a proprietary audio processor. The shared library is **not** part of
RadioRing and is **not** covered by RadioRing's AGPL-3.0 licence. It is the property of
Thimeo Audio Technology B.V. and is used under Thimeo's own licence terms, which you can
read at <https://www.thimeo.com/stereo-tool/> and in `LICENSE-Thimeo.md` next to this
file.

Thimeo has granted the RadioRing project permission to distribute the library inside the
published station image, on the condition that this is stated clearly. That is what this
notice does.

**This permission was granted to the RadioRing project.** It does not travel with a fork.
If you build and publish your own station image containing Stereo Tool, you need your own
permission from Thimeo.

Operators additionally have to accept the Thimeo licence once per instance, in the admin
area under instance settings, before Stereo Tool can be enabled for any station.

A Thimeo licence key is **not** shipped and is licensed per stream. Each station stores
its own key, encrypted, in `stations.stereo_tool_license_key`. Without a valid key Stereo
Tool runs in demo mode and mixes noise into the audio at intervals, which is why RadioRing
only wires the operator into the Liquidsoap script once a licence key is present.

Path inside the image, configurable through `STEREO_TOOL_LIBRARY_FILE`:
`/opt/stereotool/libStereoTool.so`.

`.sts` presets are **not** in the image. They are settings files, not Thimeo software, and
the entrypoint fetches the selected one from the API before every Liquidsoap start.

Liquidsoap loads the library at runtime via its `stereotool` operator (`dlopen`), it is
not linked against it at build time.
