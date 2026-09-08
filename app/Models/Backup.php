<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single backup run and, once it succeeded, the archive it produced.
 */
#[Fillable([
    'filename',
    'kind',
    'status',
    'size_bytes',
    'encrypted',
    'automatic',
    'error',
    'started_at',
    'finished_at',
    'created_by',
])]
class Backup extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Directory below the `local` disk that holds every archive.
     */
    public const DIRECTORY = 'backups';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'encrypted' => 'boolean',
            'automatic' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->filename !== null;
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /**
     * Path of the archive relative to the `local` disk.
     */
    public function path(): ?string
    {
        return $this->filename === null ? null : self::DIRECTORY.'/'.$this->filename;
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes === null) {
            return '-';
        }

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $index => $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return $index === 0
                    ? $bytes.' B'
                    : number_format($bytes, $bytes < 10 ? 1 : 0, ',', '.').' '.$unit;
            }

            $bytes /= 1024;
        }

        return '-';
    }
}
