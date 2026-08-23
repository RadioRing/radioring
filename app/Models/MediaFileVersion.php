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
#[Fillable(['media_file_id', 'file_path', 'original_filename', 'duration_seconds', 'loudness_lufs', 'loudness_true_peak', 'replaced_by_user_id'])]
class MediaFileVersion extends Model
{
    protected function casts(): array
    {
        return [
            'loudness_lufs' => 'float',
            'loudness_true_peak' => 'float',
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
        if (! $this->duration_seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->duration_seconds, 60), $this->duration_seconds % 60);
    }
}
