# Stereo Tool (Thimeo) – Binärdateien hier ablegen

Dieser Ordner wird beim Image-Build via `COPY stereotool/ /opt/stereotool/` in den
Liquidsoap-Station-Container kopiert.

Stereo Tool ist ein **optionales, proprietaeres Add-on von Thimeo** und wird mit RadioRing
**nicht** ausgeliefert. Wer es nutzen will, lizenziert es selbst und legt die Dateien hier
ab, bevor er das Station-Image baut. Das so gebaute Image darf dann nicht oeffentlich
verteilt werden.

Ohne gueltige Lizenz laeuft Stereo Tool im Demo-Modus und mischt regelmaessige
Stoergeraeusche in den Ton. Dann besser ganz weglassen.

## Was hier hineingehört

```
stereotool/
├── libStereoTool.so        # Thimeo Stereo Tool Shared-Library (Linux, x86_64)
└── presets/
    ├── neutral.sts         # Preset "Neutral / Leicht"
    ├── pop.sts             # Preset "Pop / CHR (kräftig)"
    ├── talk.sts            # Preset "Wort / Talk"
    └── loud.sts            # Preset "Maximale Lautheit"
```

Die Dateinamen der Presets **müssen** exakt den Enum-Werten in
`app/Enums/StereoToolPreset.php` entsprechen (`<wert>.sts`). Die Ziel-Pfade im
Container werden über `config/radioring.php` gesteuert:

- `radioring.stereo_tool.library_file` → `/opt/stereotool/libStereoTool.so`
- `radioring.stereo_tool.presets_path`  → `/opt/stereotool/presets`

## Hinweise

- Die `.so` ist relativ groß (~einige MB bis zweistellige MB). Bei Bedarf `git lfs`
  verwenden.
- Ein Lizenzschlüssel wird **nicht** hier hinterlegt, sondern pro Station im Portal
  (verschlüsselt in `stations.stereo_tool_license_key`).
- Ohne gültige Lizenz läuft Stereo Tool im Demo-Modus (periodische Aussetzer) –
  RadioRing bindet den Operator daher nur ein, wenn Lizenz **und** Preset gesetzt sind.
