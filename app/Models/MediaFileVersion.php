<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine frueher ausgespielte Fassung einer Mediendatei, die durch einen Upload
 * ersetzt wurde. Die Datei bleibt liegen, solange noch generierte Rundowns auf
 * ihren Pfad zeigen; danach raeumt media:prune-replaced sie ab.
 */
#[Fillable(['media_file_id', 'file_path', 'original_filename', 'duration_seconds', 'loudness_lufs', 'loudness_true_peak', 'cue_in_seconds', 'cue_out_seconds', 'segue_seconds', 'replaced_by_user_id'])]
class MediaFileVersion extends Model
{
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'loudness_lufs' => 'float',
            'loudness_true_peak' => 'float',
            'cue_in_seconds' => 'float',
            'cue_out_seconds' => 'float',
            'segue_seconds' => 'float',
        ];
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by_user_id');
    }

    public function filename(): string
    {
        return basename($this->file_path);
    }

    public function durationFormatted(): ?string
    {
        // Exact to the millisecond once the waveform job measured it, shown in whole seconds.
        $seconds = $this->duration_seconds !== null ? (int) round($this->duration_seconds) : 0;

        if (! $seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
