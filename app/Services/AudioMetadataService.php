<?php

namespace App\Services;

use getID3;

class AudioMetadataService
{
    /**
     * Liest ID3-Tags und Abspieldauer aus einer Audiodatei.
     *
     * @return array{title: ?string, artist: ?string, album: ?string, duration: ?int}
     */
    public function read(string $filePath): array
    {
        try {
            $getID3 = new getID3;
            $info = $getID3->analyze($filePath);

            $title = $info['tags']['id3v2']['title'][0]
                ?? $info['tags']['id3v1']['title'][0]
                ?? $info['tags']['vorbiscomment']['title'][0]
                ?? null;

            $artist = $info['tags']['id3v2']['artist'][0]
                ?? $info['tags']['id3v1']['artist'][0]
                ?? $info['tags']['vorbiscomment']['artist'][0]
                ?? null;

            $album = $info['tags']['id3v2']['album'][0]
                ?? $info['tags']['id3v1']['album'][0]
                ?? $info['tags']['vorbiscomment']['album'][0]
                ?? null;

            $duration = isset($info['playtime_seconds'])
                ? (int) round($info['playtime_seconds'])
                : null;

            return [
                'title' => $title ? trim($title) : null,
                'artist' => $artist ? trim($artist) : null,
                'album' => $album ? trim($album) : null,
                'duration' => $duration,
            ];
        } catch (\Throwable) {
            return ['title' => null, 'artist' => null, 'album' => null, 'duration' => null];
        }
    }
}
