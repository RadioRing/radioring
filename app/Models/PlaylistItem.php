<?php

namespace App\Models;

use Database\Factories\PlaylistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['playlist_id', 'media_file_id', 'external_source_id', 'container_playlist_id', 'position', 'type', 'title', 'file_path', 'url', 'duration_seconds', 'relative_offset_seconds', 'fixed_mode', 'fill_tags', 'fill_max_duration_seconds'])]
class PlaylistItem extends Model
{
    /** @use HasFactory<PlaylistItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fill_tags' => 'array',
        ];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class);
    }

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    /** The container this item embeds (only set for type "container"). */
    public function containerPlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'container_playlist_id');
    }

    /** Is this a fixed time marker? */
    public function isMarker(): bool
    {
        return $this->type === 'marker';
    }

    public function isHardMarker(): bool
    {
        return $this->isMarker() && $this->fixed_mode === 'hard';
    }

    /** Dateiname, bevorzugt aus verknüpfter MediaFile, sonst direkter file_path. */
    public function filename(): ?string
    {
        if ($this->mediaFile) {
            return $this->mediaFile->filename();
        }

        return $this->file_path ? basename($this->file_path) : null;
    }

    /** Formatiert duration_seconds als MM:SS, bevorzugt aus MediaFile. */
    public function durationFormatted(): ?string
    {
        $seconds = $this->mediaFile?->duration_seconds ?? $this->duration_seconds;

        if (! $seconds) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
