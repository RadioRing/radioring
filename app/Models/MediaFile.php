<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['tenant_id', 'title', 'artist', 'album', 'notes', 'type', 'fade_in', 'airtime_windows', 'file_path', 'duration_seconds', 'loudness_lufs', 'loudness_true_peak', 'loudness_measured_at'])]
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fade_in' => 'boolean',
            'airtime_windows' => 'array',
            'loudness_lufs' => 'float',
            'loudness_true_peak' => 'float',
            'loudness_measured_at' => 'datetime',
        ];
    }

    /**
     * Gain (in dB) that lifts the track to the target loudness, as a liq_amplify
     * override for Liquidsoap. Capped so the raised true peak stays below clipping:
     * quiet but peaky tracks would otherwise distort.
     *
     * @return float|null Null while the track has not been measured.
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
            // Drop playlist and rundown items so no entry is left without its file.
            $file->playlistItems()->delete();
            $file->generatedPlaylistItems()->delete();

            // Clear replaced versions off disk; the rows go with the foreign key.
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
     * Replaced versions of this file, newest first.
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

    /** The windows as one line, e.g. "Mon, Tue 06:00-10:00"; null when ungated. */
    public function airtimeWindowsSummary(): ?string
    {
        if (empty($this->airtime_windows)) {
            return null;
        }

        $labels = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];

        return collect($this->airtime_windows)
            ->map(function (array $window) use ($labels): string {
                $days = array_map('intval', $window['days'] ?? []);
                $dayPart = $days === []
                    ? __('Daily')
                    : implode(', ', array_map(fn (int $day): string => $labels[$day] ?? (string) $day, $days));

                return $dayPart.' '.($window['from'] ?? '').'-'.($window['to'] ?? '');
            })
            ->implode(' · ');
    }

    /**
     * Narrows a query to the files that may air at the given moment.
     *
     * Only gated files are loaded and filtered in PHP: weekday lists and windows
     * running past midnight do not translate into portable JSON SQL.
     */
    public function scopeAirableAt(Builder $query, CarbonInterface $at): Builder
    {
        $blockedIds = (clone $query)
            ->whereNotNull('airtime_windows')
            ->get(['id', 'airtime_windows'])
            ->reject(fn (MediaFile $file): bool => $file->isAirableAt($at))
            ->pluck('id')
            ->all();

        return $blockedIds === [] ? $query : $query->whereNotIn('id', $blockedIds);
    }

    /**
     * May this file be picked automatically for the given airtime? Windows are an
     * OR-list: one match is enough, and no windows means no restriction.
     */
    public function isAirableAt(CarbonInterface $at): bool
    {
        $windows = $this->airtime_windows;

        if (empty($windows)) {
            return true;
        }

        foreach ($windows as $window) {
            if ($this->windowCovers($window, $at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does one window cover the given moment? No weekdays means every day. The start
     * time pins a window to its weekday, so one running past midnight (from > to)
     * reaches into the next day without that day being listed.
     *
     * @param  array{days?: array<int, int|string>, from?: string, to?: string}  $window
     */
    private function windowCovers(array $window, CarbonInterface $at): bool
    {
        $from = $this->minutesOfDay($window['from'] ?? null);
        $to = $this->minutesOfDay($window['to'] ?? null);

        if ($from === null || $to === null) {
            return false;
        }

        $days = array_map('intval', $window['days'] ?? []);
        $matchesDay = fn (int $isoWeekday): bool => $days === [] || in_array($isoWeekday, $days, true);

        $minutes = $at->hour * 60 + $at->minute;

        // Equal bounds describe a whole day rather than an empty window.
        if ($from === $to) {
            return $matchesDay($at->dayOfWeekIso);
        }

        if ($from < $to) {
            return $matchesDay($at->dayOfWeekIso) && $minutes >= $from && $minutes < $to;
        }

        // Past midnight: either the tail of a window that started today, or one that
        // started on the previous day.
        if ($minutes >= $from) {
            return $matchesDay($at->dayOfWeekIso);
        }

        return $minutes < $to && $matchesDay($at->copy()->subDay()->dayOfWeekIso);
    }

    /** Turns "06:30" into minutes since midnight, or null if the value is unusable. */
    private function minutesOfDay(?string $time): ?int
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
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

    /** How many playlists use this file. */
    public function usageCount(): int
    {
        return $this->playlistItems()->count();
    }
}
