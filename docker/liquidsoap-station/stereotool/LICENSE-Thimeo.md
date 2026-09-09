# Stereo Tool is licensed by Thimeo, not by RadioRing

`libStereoTool.so` in this directory, the Stereo Tool shared library, belongs to **Thimeo
Audio Technology B.V.**, not to the RadioRing project.

It is proprietary software. RadioRing's AGPL-3.0 licence does **not** apply to it, and
nothing in it grants you any right to it. Your use of Stereo Tool is governed solely by
Thimeo's own licence terms:

<https://www.thimeo.com/stereo-tool/>

## Why it is here

Thimeo has granted the RadioRing project permission to distribute the library inside the
published `liquidsoap-station` image, on the condition that the licence it falls under is
stated clearly. This file, `../NOTICE.md` and the "Third-party components" section of the
project README do that.

**The permission was granted to the RadioRing project and does not travel with a fork.**
If you build and publish your own image containing Stereo Tool, obtain your own
permission from Thimeo first.

## You still need your own licence key

The key is licensed per stream and is not shipped here. Each station stores its own key,
encrypted, and RadioRing only enables the processing once a key is present.
Without a valid key Stereo Tool runs in demo mode and mixes noise into the audio at
intervals.

## Presets are elsewhere

`.sts` presets are settings files, not Thimeo software, and are not shipped here. The
entrypoint fetches the one a station selected from the API before every Liquidsoap start.
See `resources/stereo-tool-presets/README.md`.

## Using your own build

Mount your own library into the container and point `STEREO_TOOL_LIBRARY_FILE` at it.
