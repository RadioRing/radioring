<?php

namespace App\Models;

use Database\Factories\GeneratedPlaylistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['generated_playlist_id', 'media_file_id', 'media_file_path', 'external_source_id', 'position', 'title', 'duration_seconds', 'absolute_broadcast_at', 'source_type', 'prepared_path', 'prepared_at', 'loudness_lufs', 'loudness_true_peak'])]
class GeneratedPlaylistItem extends Model
{
    /** @use HasFactory<GeneratedPlaylistItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'absolute_broadcast_at' => 'datetime',
            'prepared_at' => 'datetime',
            'loudness_lufs' => 'float',
            'loudness_true_peak' => 'float',
        ];
    }

    public function generatedPlaylist(): BelongsTo
    {
        return $this->belongsTo(GeneratedPlaylist::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    /**
     * Berechnet aus der (pro Ausspielung) gemessenen Lautheit den liq_amplify-Gain in dB.
     * Spiegelt MediaFile::loudnessGainDb() für die vorbereitete Kopie dynamischer Quellen.
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

    /**
     * Der bei der Generierung eingefrorene Dateipfad, sofern die Mediendatei seit dem
     * ersetzt wurde und die alte Fassung noch auf der Platte liegt. Dieser Rundown
     * spielt sie dann zu Ende; erst eine Neu-Generierung nimmt die neue Fassung.
     */
    public function supersededPath(): ?string
    {
        if ($this->media_file_path === null || $this->mediaFile === null) {
            return null;
        }

        if ($this->media_file_path === $this->mediaFile->file_path) {
            return null;
        }

        return Storage::disk('local')->exists($this->media_file_path)
            ? $this->media_file_path
            : null;
    }

    public function durationFormatted(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
