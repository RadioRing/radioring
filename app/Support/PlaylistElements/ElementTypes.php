<?php

namespace App\Support\PlaylistElements;

use InvalidArgumentException;

/**
 * Maps the type picked in the editor to the class that knows how to add it.
 */
class ElementTypes
{
    /**
     * @param  string  $addMode  'library' or 'upload', only meaningful for media elements
     */
    public static function for(string $type, string $addMode = 'library'): PlaylistElementType
    {
        return match ($type) {
            'adbreak' => new AdBreakElement,
            'random' => new RandomElement,
            'fill' => new FillElement,
            'url' => new UrlElement,
            'external' => new ExternalElement,
            'container' => new ContainerElement,
            'music', 'jingle' => $addMode === 'upload'
                ? new UploadElement($type)
                : new LibraryElement,
            default => throw new InvalidArgumentException("Unbekannter Element-Typ: {$type}"),
        };
    }

    /**
     * Types the operator can pick in the add form, in the order they are offered,
     * keyed by type with the label for the dropdown.
     *
     * The legacy news/weather types are deliberately missing: they still play from old
     * playlists but are replaced by external sources for anything new.
     *
     * @param  bool  $allowContainer  false inside a container, which must not nest
     * @return array<string, string>
     */
    public static function selectableLabels(bool $allowContainer = true): array
    {
        $labels = [
            'music' => __('Musik'),
            'jingle' => __('Jingle'),
            'external' => __('Externe Quelle'),
            'url' => __('URL (Legacy)'),
            'fill' => __('Auffüllen mit Musik'),
            'random' => __('Zufälliges Element'),
            'adbreak' => __('Werbeunterbrechung (laut.fm)'),
            'container' => __('Container'),
        ];

        if (! $allowContainer) {
            unset($labels['container']);
        }

        return $labels;
    }

    /** Does this type need a media file picked from the library or uploaded? */
    public static function needsMediaFile(string $type): bool
    {
        return in_array($type, ['music', 'jingle'], true);
    }

    /**
     * May this type carry a timestamp? Elements that are resolved at generation time
     * (fill, random) or that mark a position (ad break, container) may not.
     */
    public static function supportsTimestamp(string $type): bool
    {
        return ! in_array($type, ['fill', 'random', 'adbreak', 'container'], true);
    }
}
