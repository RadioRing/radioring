<?php

namespace App\Models;

use App\Jobs\WriteMediaTagsJob;
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

#[Fillable(['tenant_id', 'title', 'artist', 'album', 'notes', 'type', 'fade_in', 'airtime_windows', 'airable_from', 'airable_until', 'file_path', 'duration_seconds', 'loudness_lufs', 'loudness_true_peak', 'loudness_measured_at'])]
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory;

    /** @var list<string> */
    public const TYPES = ['music', 'jingle', 'voicetrack'];

    /** Display name of a media type. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'music' => __('Musik'),
            'jingle' => __('Jingle'),
            'voicetrack' => __('Voice track'),
            default => $type,
        };
    }

    /** Bootstrap badge classes of a media type. */
    public static function typeBadgeClass(string $type): string
    {
        return match ($type) {
            'music' => 'bg-primary',
            'jingle' => 'bg-warning text-dark',
            'voicetrack' => 'bg-voicetrack',
            default => 'bg-secondary',
        };
    }

    protected function casts(): array
    {
        return [
            'fade_in' => 'boolean',
            'airtime_windows' => 'array',
            'airable_from' => 'datetime',
            'airable_until' => 'datetime',
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
        // Panel edits, upload form values and replaced files all end up in the audio file.
        static::created(function (MediaFile $file): void {
            WriteMediaTagsJob::dispatch($file->id)->afterCommit();
        });

        static::updated(function (MediaFile $file): void {
            if ($file->wasChanged(['title', 'artist', 'album', 'file_path'])) {
                WriteMediaTagsJob::dispatch($file->id)->afterCommit();
            }
        });

        static::deleting(function (MediaFile $file): void {
            // Drop playlist and rundown items so no entry is left without its file.
            $file->playlistItems()->delete();
            $file->removeFromUnpulledRundownItems();

            // Clear replaced versions off disk; the rows go with the foreign key.
            $file->versions->each(function (MediaFileVersion $version): void {
                Storage::disk('local')->delete($version->file_path);
            });
        });
    }

    /**
     * Deletes the rundown items of this file that Liquidsoap has not pulled yet and closes
     * the gaps they leave.
     *
     * Items already handed to Liquidsoap stay: the one on air anchors the dashboard, skip
     * and restart logic via now_playing_item_id, and the prefetched ones play regardless.
     * Deleting them used to null the anchor, so the dashboard fell back to projecting the
     * whole hour from position 0 until the next track reported in. Their media_file_id is
     * nulled by the foreign key.
     */
    public function removeFromUnpulledRundownItems(): void
    {
        $states = [];
        $touchedRundowns = [];

        $this->generatedPlaylistItems()->with('generatedPlaylist')->get()
            ->each(function (GeneratedPlaylistItem $item) use (&$states, &$touchedRundowns): void {
                $rundown = $item->generatedPlaylist;

                $state = $states[$rundown->station_id] ??= LiquidsoapState::with('currentRundown')
                    ->where('station_id', $rundown->station_id)
                    ->first() ?? false;

                if ($state && $this->wasPulled($item, $rundown, $state)) {
                    return;
                }

                $item->delete();
                $touchedRundowns[$rundown->id] = $rundown;
            });

        foreach ($touchedRundowns as $rundown) {
            $rundown->items()->get()->each(function (GeneratedPlaylistItem $item, int $index): void {
                if ($item->position !== $index) {
                    $item->update(['position' => $index]);
                }
            });
        }
    }

    /** Has Liquidsoap already pulled this rundown item (on air, prefetched or past)? */
    private function wasPulled(GeneratedPlaylistItem $item, GeneratedPlaylist $rundown, LiquidsoapState $state): bool
    {
        if ($item->id === $state->now_playing_item_id) {
            return true;
        }

        $current = $state->currentRundown;

        if (! $current) {
            return false;
        }

        if ($rundown->id === $current->id) {
            return $item->position < $state->current_item_position;
        }

        $rundownDate = $rundown->broadcast_date->toDateString();
        $currentDate = $current->broadcast_date->toDateString();

        return $rundownDate < $currentDate
            || ($rundownDate === $currentDate && $rundown->broadcast_hour < $current->broadcast_hour);
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
     * The run time is plain SQL. Of the files left, only those with windows are loaded
     * and filtered in PHP: weekday lists and windows running past midnight do not
     * translate into portable JSON SQL.
     */
    public function scopeAirableAt(Builder $query, CarbonInterface $at): Builder
    {
        $query->where(fn (Builder $q) => $q->whereNull('airable_from')->orWhere('airable_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('airable_until')->orWhere('airable_until', '>', $at));

        $blockedIds = (clone $query)
            ->whereNotNull('airtime_windows')
            ->get(['id', 'airtime_windows'])
            ->reject(fn (MediaFile $file): bool => $file->isAirableAt($at))
            ->pluck('id')
            ->all();

        return $blockedIds === [] ? $query : $query->whereNotIn('id', $blockedIds);
    }

    /**
     * May this file be picked automatically for the given airtime? The run time comes
     * first. Windows are an OR-list: one match is enough, and no windows means no
     * restriction.
     */
    public function isAirableAt(CarbonInterface $at): bool
    {
        if (! $this->isInRunTime($at)) {
            return false;
        }

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

    /** Inside the optional run time? The start counts, the end does not. */
    public function isInRunTime(CarbonInterface $at): bool
    {
        if ($this->airable_from !== null && $at->lt($this->airable_from)) {
            return false;
        }

        return $this->airable_until === null || $at->lt($this->airable_until);
    }

    /** The run time as one line, e.g. "2026-12-01 00:00 until 2026-12-27 00:00"; null when open. */
    public function runTimeSummary(): ?string
    {
        $format = __('Y-m-d H:i');

        return match (true) {
            $this->airable_from !== null && $this->airable_until !== null => __(':from until :until', [
                'from' => $this->airable_from->format($format), 'until' => $this->airable_until->format($format),
            ]),
            $this->airable_from !== null => __('from :from', ['from' => $this->airable_from->format($format)]),
            $this->airable_until !== null => __('until :until', ['until' => $this->airable_until->format($format)]),
            default => null,
        };
    }

    /** Has the run time ended for good? */
    public function hasExpired(?CarbonInterface $now = null): bool
    {
        return $this->airable_until !== null && ($now ?? now())->gte($this->airable_until);
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
