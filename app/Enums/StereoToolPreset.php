<?php

namespace App\Enums;

/**
 * Auswählbare Stereo-Tool-Presets. Jeder Wert entspricht einer .sts-Preset-Datei,
 * die im Liquidsoap-Container unter config('radioring.stereo_tool.presets_path')
 * abgelegt ist (Dateiname = Enum-Wert + ".sts").
 */
enum StereoToolPreset: string
{
    case Neutral = 'neutral';
    case Pop = 'pop';
    case Talk = 'talk';
    case Loud = 'loud';

    /** Für die UI-Auswahl lesbare Bezeichnung. */
    public function label(): string
    {
        return match ($this) {
            self::Neutral => __('Neutral / Leicht'),
            self::Pop => __('Pop / CHR (kräftig)'),
            self::Talk => __('Wort / Talk'),
            self::Loud => __('Maximale Lautheit'),
        };
    }

    /** Absoluter Pfad zur Preset-Datei im Container. */
    public function filePath(): string
    {
        $dir = rtrim((string) config('radioring.stereo_tool.presets_path'), '/');

        return "{$dir}/{$this->value}.sts";
    }

    /**
     * Presets als [Wert => Label] für Dropdowns.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $preset) => [$preset->value => $preset->label()])
            ->all();
    }
}
