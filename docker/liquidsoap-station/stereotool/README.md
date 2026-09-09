# Stereo Tool (Thimeo) build inputs

This directory is copied into the station image with `COPY stereotool/ /opt/stereotool/`.
The licence situation is described in `LICENSE-Thimeo.md`; read that first.

The binary artefacts are **not** checked into git. They are fetched during the image
build, so that the repository stays free of large binaries and so that the shipped
version is a reviewable, pinned value rather than whatever was on someone's disk.

## What ends up here at build time

```
stereotool/
├── README.md               # this file
├── LICENSE-Thimeo.md       # shipped into the image
├── sources.json            # where to fetch from, and the expected checksum
└── libStereoTool.so        # extracted from the archive, renamed, per architecture
```

**Presets are not in the image.** The entrypoint fetches the selected `.sts` from the API
before every Liquidsoap start, so a new preset costs an app deploy rather than an image
build, and presets shipped with RadioRing take the same path as presets a station
uploaded. See `resources/stereo-tool-presets/README.md`.

The path inside the container is configurable through `config/radioring.php`:
`radioring.stereo_tool.library_file` defaults to `/opt/stereotool/libStereoTool.so`.

## sources.json

`.github/workflows/liquidsoap-station.yml` reads this file, downloads the archive,
verifies its SHA-256 and extracts the build matching the platform it is building.
Bumping the Stereo Tool version means editing this one file.

Thimeo publishes a single archive for every platform behind an **unversioned** URL, so the
checksum is the only thing pinning the version. When Thimeo releases a new build, the
checksum stops matching and this step fails until someone bumps `sources.json`. That is
deliberate: the processor sitting in the signal path should not change without anyone
deciding to change it.

```json
{
  "version": "11.05",
  "url": "https://download.thimeo.com/Stereo_Tool_Generic_plugin.zip",
  "sha256": "08ad70...",
  "library_member": {
    "linux/amd64": "*libStereoTool_intel64.so",
    "linux/arm64": "*libStereoTool_noX11_arm64.so"
  }
}
```

### Which build to pick

The archive carries Windows, macOS, iOS and Linux builds, and for Linux both an X11 and a
headless variant. The naming is not symmetric between architectures, which is easy to get
wrong:

| Platform      | Member                            | Needs X11                      |
| ------------- | --------------------------------- | ------------------------------ |
| `linux/amd64` | `libStereoTool_intel64.so`        | no                             |
| `linux/amd64` | `libStereoToolX11_intel64.so`     | yes, do not use                |
| `linux/arm64` | `libStereoTool_noX11_arm64.so`    | no                             |
| `linux/arm64` | `libStereoTool_arm64.so`          | yes, do not use                |

On Intel the plain name is the headless build; on ARM the plain name is the X11 build and
the headless one carries `noX11`. An X11 build pulls in `libX11`, `libXfixes` and `libXpm`,
none of which exist in the station container.

Members are matched on the file name, not on a path: the archive is produced on Windows
and uses backslashes as separators. The Kantar directory holds a second copy of the Intel
build under the same file name and is excluded explicitly, so flattening cannot pick the
wrong one. After extraction the workflow checks the ELF architecture, because a wrong
build would otherwise only surface as a container that fails to load the operator.

Both Linux builds link against `libasound.so.2`, which the Liquidsoap base image already
provides.

### Skipping it

An empty `url` means "skip". The image then builds **without** Stereo Tool, which is a
supported outcome: the operator can still mount their own library and point
`STEREO_TOOL_LIBRARY_FILE` at it. Nothing else in RadioRing breaks, because the Liquidsoap
script only references Stereo Tool for stations that have it enabled.

`sha256` is required whenever `url` is set. A download whose checksum does not match fails
the build rather than silently shipping an unexpected binary.

Note that the plugin archive contains no `.sts` presets anyway, only headers and
libraries.

## Building locally

Place `libStereoTool.so` here by hand and run the normal `docker build`. The Dockerfile
does no downloading of its own.
