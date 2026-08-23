<?php

namespace App\Models;

use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'title', 'artist', 'album', 'notes', 'type', 'fade_in', 'file_path', 'duration_seconds', 'loudness_lufs', 'loudness_true_peak', 'loudness_measured_at'])]
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fade_in' => 'boolean',
            'loudness_lufs' => 'float',
            'loudness_true_peak' => 'float',
            'loudness_measured_at' => 'datetime',
        ];
    }

    /**
     * Berechnet aus der gemessenen Lautheit den Gain (in dB), mit dem der Track auf
     * die Ziel-Lautheit gebracht wird – als liq_amplify-Override für Liquidsoap.
     *
     * Begrenzt nach oben, damit der angehobene True-Peak die Clipping-Schwelle nicht
     * überschreitet (leise, aber peakige Tracks würden sonst verzerren).
     *
     * @return float|null Null, wenn der Track noch nicht gemessen wurde.
     */
    public function loudnessGainDb(): ?float
    {
        if ($this->loudness_lufs === null) {
            return null;
        }

        $target = config('radioring.loudness.target_lufs');
        $target = $target !== null ? (float) $target : -14.0;

        $gain = $target - $this->loudness_lufs;

        if ($this->loudness_true_peak !== null) {
            $maxTruePeak = (float) config('radioring.loudness.max_true_peak_dbtp', -1.0);
            $gain = min($gain, $maxTruePeak - $this->loudness_true_peak);
        }

        return round($gain, 2);
    }

    protected static function booted(): void
    {
        static::deleting(function (MediaFile $file): void {
            // Playlist-Template-Items und Rundown-Items entfernen,
            // damit keine verwaisten Einträge ohne Mediendatei zurückbleiben.
            $file->playlistItems()->delete();
            $file->generatedPlaylistItems()->delete();

            // Ersetzte Fassungen von der Platte räumen – ohne die Datei referenziert
            // sie niemand mehr. Die Datensätze selbst entfernt der Fremdschlüssel.
            $file->versions->each(function (MediaFileVersion $version): void {
                Storage::disk('local')->delete($version->file_path);
            });
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
     * Ersetzte Fassungen dieser Datei, neueste zuerst.
     *
     * @return HasMany<MediaFileVersion>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(MediaFileVersion::class)->latest('id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'media_file_tags');
    }

    public function filename(): string
    {
        return basename($this->file_path);
    }

    public function durationFormatted(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }

    /** Anzahl der Playlisten, in denen diese Datei verwendet wird. */
    public function usageCount(): int
    {
        return $this->playlistItems()->count();
    }
}
