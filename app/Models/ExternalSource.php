<?php

namespace App\Models;

use Database\Factories\ExternalSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine wiederverwendbare externe HTTP-Quelle (Syndication, laut.fm-Nachrichten/Wetter).
 * Dynamischer Inhalt: wird kurz vor Ausspielung geholt, geprüft und (optional) normalisiert.
 */
#[Fillable([
    'station_id',
    'name',
    'kind',
    'url',
    'syndication_sendung_id',
    'syndication_variant',
    'syndication_filename',
    'expected_duration_seconds',
    'prefetch_lead_seconds',
    'freshness_seconds',
    'normalize',
    'trim_leading_silence',
    'fade_in',
    'last_fetched_at',
    'last_loudness_lufs',
    'last_true_peak',
    'last_error',
])]
class ExternalSource extends Model
{
    /** @use HasFactory<ExternalSourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'normalize' => 'boolean',
            'trim_leading_silence' => 'boolean',
            'fade_in' => 'boolean',
            'syndication_sendung_id' => 'integer',
            'last_fetched_at' => 'datetime',
            'last_loudness_lufs' => 'float',
            'last_true_peak' => 'float',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function playlistItems(): HasMany
    {
        return $this->hasMany(PlaylistItem::class);
    }

    public function generatedPlaylistItems(): HasMany
    {
        return $this->hasMany(GeneratedPlaylistItem::class);
    }

    /**
     * Baut die URL einer laut.fm-Nachrichten/Wetter-Quelle aus den Credentials des
     * laut.fm-Ausgangs der Station. Für kind=url wird die hinterlegte URL zurückgegeben.
     */
    public function resolveUrl(): ?string
    {
        if ($this->kind === 'url') {
            return $this->url;
        }

        if ($this->kind === 'syndication') {
            $client = $this->station->s4rClient();

            if (! $client || ! $this->syndication_sendung_id) {
                return null;
            }

            return $client->signedUrlForFile($this->syndication_sendung_id, $this->syndication_variant, $this->syndication_filename);
        }

        $output = $this->station->lautfmOutput();

        if (! $output || ! $output->username || ! $output->password) {
            return null;
        }

        $segment = match ($this->kind) {
            'news_weather' => 1,
            'news' => 2,
            'weather' => 3,
        };

        $mount = rawurlencode($output->mountName());

        return "https://{$mount}:".rawurlencode($output->password).'@api.radioadmin.laut.fm/news/'.$segment;
    }

    /** Erwartete Dauer als m:ss (oder null, wenn nicht gesetzt). */
    public function expectedDurationFormatted(): ?string
    {
        if (! $this->expected_duration_seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->expected_duration_seconds, 60), $this->expected_duration_seconds % 60);
    }

    /** Anzahl der Playlisten-Items, die diese Quelle referenzieren. */
    public function usageCount(): int
    {
        return $this->playlistItems()->count();
    }
}
