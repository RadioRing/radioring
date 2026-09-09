# Stereo Tool presets shipped with RadioRing

Every `.sts` file in this directory is offered to all stations in the portal, under
"Shipped with RadioRing". Adding one is a pull request; nothing has to be rebuilt into the
station image, because presets are served from here to the container at runtime.

The file name is the identifier (`weather.sts` becomes `bundled:weather`), so renaming a
file silently unselects it for every station using it. Treat names as permanent.

## Contributing a preset

`.sts` is plain INI text: bracketed section headers and `key=value` lines. That makes a
preset reviewable as a normal diff, which is the whole reason we take them by pull
request.

Please include a `[Preset info]` section. The `Name=` value is what the portal shows; the
`Contains ... settings=` flags decide which parts of the processing chain your preset
touches, so a focused preset should set the flags it does not touch to `0`:

```ini
[Preset info]
Name=Loudness only
Contains AGC settings=1
Contains Multiband Compressor 1 settings=0
...
```

Along with the file, say in the pull request what the preset is for and what it
deliberately leaves alone.

## Licensing

**Contribute only presets you made yourself.** A `.sts` is your settings, not Thimeo's
software, and by opening the pull request you licence it to the project under the
[project licence](../../LICENSE) so it can ship with RadioRing.

Presets from forums, from other stations, or bundled with a Stereo Tool installation
belong to whoever made them. Do not add those here, however freely they are passed around.
If you want to use one, upload it for your own station in the portal instead: uploads stay
private to that station and are never redistributed.

Note that this is separate from the Thimeo licence covering the Stereo Tool software
itself, described in `docker/liquidsoap-station/stereotool/LICENSE-Thimeo.md`.
