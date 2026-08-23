# Bundled assets

## `ad_break.mp3`

A silent MP3 with no audio content. It carries no copyright and is not meant to be heard.

It exists only so that Liquidsoap has a file to annotate with `title="START_ADBREAK"`.
laut.fm reads that title from the stream metadata and starts its own ad break; the audio
itself is irrelevant, only the tag matters.

Mounted into the container at `/opt/ad_break.mp3`, configurable through
`ADBREAK_SIGNAL_PATH`.

## `stereotool/`

Empty except for a README. Thimeo Stereo Tool is proprietary and is **not** distributed
with RadioRing. Operators who want it license it themselves and supply the shared library
and presets. See `stereotool/README.md`.
