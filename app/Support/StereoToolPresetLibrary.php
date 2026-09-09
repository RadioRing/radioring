<?php

namespace App\Support;

use App\Models\Station;
use App\Models\StereoToolPreset;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves the identifier stored in stations.stereo_tool_preset to a .sts file.
 *
 * - "bundled:<slug>" is a file under resources/stereo-tool-presets, contributed by pull
 *   request and identical on every installation.
 * - "upload:<id>" belongs to one station (StereoToolPreset).
 *
 * A dangling identifier resolves to null rather than throwing: Stereo Tool then runs with
 * its factory settings instead of taking the station off the air.
 */
class StereoToolPresetLibrary
{
    /** Presets are plain INI text, so a sane upload is small. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public static function bundledPath(): string
    {
        return resource_path('stereo-tool-presets');
    }

    /** @return array<string, string> identifier => name */
    public static function bundled(): array
    {
        $directory = self::bundledPath();

        if (! is_dir($directory)) {
            return [];
        }

        return collect(glob($directory.'/*.sts') ?: [])
            ->sort()
            ->mapWithKeys(fn (string $file) => [
                'bundled:'.pathinfo($file, PATHINFO_FILENAME) => self::nameOf($file),
            ])
            ->all();
    }

    /** @return array<string, string> identifier => name */
    public static function uploads(Station $station): array
    {
        return $station->stereoToolPresets()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (StereoToolPreset $preset) => [$preset->identifier() => $preset->name])
            ->all();
    }

    /** @return array<int, string> */
    public static function selectableIdentifiers(Station $station): array
    {
        return array_merge(
            array_keys(self::bundled()),
            array_keys(self::uploads($station)),
        );
    }

    public static function resolve(Station $station): ?string
    {
        $identifier = (string) $station->stereo_tool_preset;

        if ($identifier === '') {
            return null;
        }

        if (str_starts_with($identifier, 'bundled:')) {
            $slug = substr($identifier, strlen('bundled:'));

            $file = self::bundledPath().'/'.$slug.'.sts';

            // Checked against the directory listing, so a slug cannot traverse out of it.
            return in_array('bundled:'.$slug, array_keys(self::bundled()), true) && is_file($file)
                ? $file
                : null;
        }

        if (str_starts_with($identifier, 'upload:')) {
            $preset = $station->stereoToolPresets()
                ->whereKey((int) substr($identifier, strlen('upload:')))
                ->first();

            return $preset?->absolutePath();
        }

        return null;
    }

    /**
     * Deliberately loose: this rejects the mp3 someone picked by mistake and leaves
     * judging the actual settings to Stereo Tool.
     */
    public static function looksLikePreset(string $contents): bool
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            return false;
        }

        if (str_contains($contents, "\0")) {
            return false;
        }

        return (bool) preg_match('/^\s*\[[^\]\r\n]+\]\s*$/m', $contents)
            && (bool) preg_match('/^[^=\r\n]+=.*$/m', $contents);
    }

    /** The name a preset calls itself, falling back to a readable form of the file name. */
    public static function nameOf(string $file): string
    {
        $fallback = Str::headline(pathinfo($file, PATHINFO_FILENAME));

        $contents = @file_get_contents($file, false, null, 0, 64 * 1024);

        if ($contents === false) {
            return $fallback;
        }

        return self::nameIn($contents) ?? $fallback;
    }

    /** Reads "Name=" out of the "[Preset info]" section, if there is one. */
    public static function nameIn(string $contents): ?string
    {
        $section = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^\[(.+)\]$/', $line, $matches)) {
                $section = $matches[1];

                continue;
            }

            if ($section === 'Preset info' && str_starts_with($line, 'Name=')) {
                $name = trim(substr($line, strlen('Name=')));

                return $name !== '' ? Str::limit($name, 80, '') : null;
            }
        }

        return null;
    }

    /** @return Collection<string, array<string, string>> */
    public static function grouped(Station $station): Collection
    {
        return collect([
            __('Shipped with RadioRing') => self::bundled(),
            __('Uploaded by this station') => self::uploads($station),
        ])->filter(fn (array $group) => $group !== []);
    }
}
