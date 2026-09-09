<?php

namespace App\Models;

use Database\Factories\StereoToolPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A .sts preset a station uploaded for itself. Presets shipped with RadioRing are files
 * in the repository, not rows here, because only uploads belong to a single station.
 */
#[Fillable(['station_id', 'uploaded_by', 'name', 'path', 'size'])]
class StereoToolPreset extends Model
{
    /** @use HasFactory<StereoToolPresetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The identifier a station stores in stations.stereo_tool_preset. */
    public function identifier(): string
    {
        return 'upload:'.$this->id;
    }

    public function absolutePath(): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($this->path) ? $disk->path($this->path) : null;
    }

    public function deleteWithFile(): void
    {
        Storage::disk('local')->delete($this->path);

        $this->delete();
    }
}
