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
     * Resolves a palette entry to its element type.
     *
     * The special tab carries the type itself as its id (fill, random, adbreak), the
     * other tabs carry a database id.
     */
    public static function forPaletteEntry(string $kind, string $id): PlaylistElementType
    {
        return match ($kind) {
            PlaylistPalette::TAB_MEDIA => new LibraryElement,
            PlaylistPalette::TAB_CONTAINER => new ContainerElement,
            PlaylistPalette::TAB_EXTERNAL => new ExternalElement,
            PlaylistPalette::TAB_SPECIAL => self::for($id),
            default => throw new InvalidArgumentException("Unbekannte Palette-Art: {$kind}"),
        };
    }
}
