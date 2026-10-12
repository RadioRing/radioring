<?php

namespace App\Livewire\MediaLibrary;

use App\Concerns\AuthorizesCurrentStation;
use App\Jobs\GenerateMediaWaveformJob;
use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Services\CuePointDetectorService;
use App\Services\MediaReplacementService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Everything that can be configured on a single media file, in one dialog: waveform,
 * metadata, tags, airtime windows, replacing the file and its version history. It sits next to
 * the library list so the dialog opens where the user is, no matter how far the list
 * is scrolled.
 */
class FileModal extends Component
{
    use AuthorizesCurrentStation;

    #[Locked]
    public Station $station;

    #[Locked]
    public ?MediaFile $file = null;

    /** Deep link: /media?file=123 opens the dialog straight away. */
    #[Url(as: 'file', except: null)]
    public ?int $openFileId = null;

    public string $title = '';

    public string $artist = '';

    public string $album = '';

    public string $notes = '';

    public string $type = 'music';

    /**
     * Cue and fade points as edited in the waveform editor, in seconds. An empty string
     * means "not set": cue in at the start, cue out at the end, no fade.
     */
    public string $cueIn = '';

    public string $cueOut = '';

    public string $fadeIn = '';

    public string $fadeOut = '';

    /** @var array<int|string> */
    public array $tagIds = [];

    /**
     * Airtime windows as edited in the form. Weekdays are ISO numbers (1 = Monday) and
     * are kept as strings because that is what checkbox binding delivers.
     *
     * @var array<int, array{days: array<int, int|string>, from: string, to: string}>
     */
    public array $airtimeWindows = [];

    /** Start of the run time as a datetime-local value (Y-m-d\TH:i), empty when open. */
    public string $airableFrom = '';

    /** End of the run time as a datetime-local value (Y-m-d\TH:i), empty when open. */
    public string $airableUntil = '';

    // Replacing: an uploaded file that has not been adopted yet
    /** @var array{path: string, filename: string, title: ?string, artist: ?string, album: ?string, duration: ?int}|null */
    public ?array $pendingReplacement = null;

    public bool $adoptMetadata = false;

    public function mount(): void
    {
        $this->station = $this->authorizedCurrentStation();

        if ($this->openFileId !== null) {
            $this->open($this->openFileId);
        }
    }

    /**
     * Opens the dialog for a file of this tenant's library.
     */
    #[On('open-media-file')]
    public function open(int $fileId): void
    {
        $file = $this->station->poolMediaFiles()->find($fileId);

        if (! $file) {
            $this->openFileId = null;

            return;
        }

        $this->file = $file;
        $this->openFileId = $file->id;
        $this->resetValidation();
        $this->fillForm();

        // Files from before the waveform existed get theirs the first time they are opened.
        // The job is unique per file, so reopening the dialog does not queue it twice.
        if ($file->waveform_path === null) {
            GenerateMediaWaveformJob::dispatch($file->id);
        }

        $this->dispatch('media-file-modal-opened');
    }

    /**
     * Closes the dialog and drops anything that was only half done inside it.
     */
    public function close(): void
    {
        $this->discardPendingReplacement();

        $this->file = null;
        $this->openFileId = null;
        $this->reset('pendingReplacement', 'adoptMetadata');
        $this->resetValidation();

        $this->dispatch('media-file-modal-closed');
    }

    private function fillForm(): void
    {
        $this->title = $this->file->title;
        $this->artist = $this->file->artist ?? '';
        $this->album = $this->file->album ?? '';
        $this->notes = $this->file->notes ?? '';
        $this->type = $this->file->type;
        $this->cueIn = self::secondsField($this->file->cue_in_seconds);
        $this->cueOut = self::secondsField($this->file->cue_out_seconds);
        $this->fadeIn = self::secondsField($this->file->fade_in_seconds);
        $this->fadeOut = self::secondsField($this->file->fade_out_seconds);
        $this->tagIds = $this->file->tags()->pluck('tags.id')->map(fn ($id) => (string) $id)->all();

        $this->airtimeWindows = collect($this->file->airtime_windows ?? [])
            ->map(fn (array $window): array => [
                'days' => array_map('strval', $window['days'] ?? []),
                'from' => $window['from'] ?? '00:00',
                'to' => $window['to'] ?? '00:00',
            ])
            ->values()
            ->all();

        $this->airableFrom = $this->file->airable_from?->format('Y-m-d\TH:i') ?? '';
        $this->airableUntil = $this->file->airable_until?->format('Y-m-d\TH:i') ?? '';
    }

    #[Computed]
    public function mayWrite(): bool
    {
        return $this->file !== null && auth()->user()->can('update', [$this->file, $this->station]);
    }

    #[Computed]
    public function mayReplace(): bool
    {
        return auth()->user()->can('deleteMedia', $this->station);
    }

    /**
     * Playlists the file is scheduled in directly.
     *
     * @return Collection<int, PlaylistItem>
     */
    #[Computed]
    public function usages(): Collection
    {
        if (! $this->file) {
            return collect();
        }

        return $this->file->playlistItems()
            ->with('playlist')
            ->get()
            ->filter(fn (PlaylistItem $item) => $item->playlist !== null)
            ->sortBy(fn (PlaylistItem $item) => $item->playlist->name)
            ->values();
    }

    /**
     * Rundowns of this station that are generated but not played yet. They hold a path
     * snapshot and keep playing the old version after a replacement until they are
     * generated again.
     *
     * @return Collection<int, GeneratedPlaylist>
     */
    #[Computed]
    public function upcomingRundowns(): Collection
    {
        if (! $this->file) {
            return collect();
        }

        $rundownIds = GeneratedPlaylistItem::where('media_file_id', $this->file->id)
            ->whereHas('generatedPlaylist', fn ($q) => $q->where('station_id', $this->station->id))
            ->pluck('generated_playlist_id')
            ->unique();

        return GeneratedPlaylist::whereIn('id', $rundownIds)
            ->where('status', '!=', 'played')
            ->whereDate('broadcast_date', '>=', today())
            ->orderBy('broadcast_date')
            ->orderBy('broadcast_hour')
            ->get();
    }

    public function addAirtimeWindow(): void
    {
        abort_unless($this->mayWrite, 403);

        $this->airtimeWindows[] = [
            'days' => ['1', '2', '3', '4', '5', '6', '7'],
            'from' => '06:00',
            'to' => '10:00',
        ];
    }

    public function removeAirtimeWindow(int $index): void
    {
        abort_unless($this->mayWrite, 403);

        unset($this->airtimeWindows[$index]);
        $this->airtimeWindows = array_values($this->airtimeWindows);
    }

    public function save(): void
    {
        abort_unless($this->mayWrite, 403);

        if (! $this->file) {
            return;
        }

        $this->validate([
            'title' => 'required|string|min:1|max:200',
            'artist' => 'nullable|string|max:200',
            'album' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
            'type' => ['required', Rule::in(MediaFile::TYPES)],
            'cueIn' => 'nullable|numeric|min:0',
            'cueOut' => 'nullable|numeric|min:0',
            'fadeIn' => 'nullable|numeric|min:0|max:60',
            'fadeOut' => 'nullable|numeric|min:0|max:60',
            'airtimeWindows' => 'array|max:20',
            'airtimeWindows.*.days' => 'array',
            'airtimeWindows.*.days.*' => 'in:1,2,3,4,5,6,7',
            'airtimeWindows.*.from' => 'required|date_format:H:i',
            'airtimeWindows.*.to' => 'required|date_format:H:i',
            'airableFrom' => 'nullable|date_format:Y-m-d\TH:i',
            'airableUntil' => ['nullable', 'date_format:Y-m-d\TH:i', ...($this->airableFrom !== '' ? ['after:airableFrom'] : [])],
        ], [
            'airableUntil.after' => __('The end of the run time must lie after its start.'),
        ]);

        $cuePoints = $this->validatedCuePoints();

        $this->file->update([
            'title' => trim($this->title),
            'artist' => trim($this->artist) !== '' ? trim($this->artist) : null,
            'album' => trim($this->album) !== '' ? trim($this->album) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            'type' => $this->type,
            ...$cuePoints,
            'airtime_windows' => $this->normalizedAirtimeWindows(),
            'airable_from' => $this->parseRunTime($this->airableFrom),
            'airable_until' => $this->parseRunTime($this->airableUntil),
        ]);

        $tenantTagIds = $this->station->tags()->pluck('id')->all();
        $this->file->tags()->sync(array_intersect(array_map('intval', $this->tagIds), $tenantTagIds));

        $this->file->refresh();
        $this->fillForm();

        $this->dispatch('media-library-changed');
        $this->dispatch('notify', message: __('Metadata saved.'), type: 'success');
    }

    /**
     * Fills cue in and cue out with what the silence detection suggests. Nothing is stored:
     * the markers move, the user checks them and saves.
     */
    public function analyzeCuePoints(CuePointDetectorService $detector): void
    {
        abort_unless($this->mayWrite, 403);

        if (! $this->file) {
            return;
        }

        $disk = Storage::disk('local');

        $suggestion = $disk->exists($this->file->file_path)
            ? $detector->detect($disk->path($this->file->file_path))
            : null;

        if ($suggestion === null) {
            $this->dispatch('notify', message: __('The file could not be analysed.'), type: 'error');

            return;
        }

        $this->resetValidation(['cueIn', 'cueOut']);
        $this->cueIn = self::secondsField($suggestion['cue_in']);
        $this->cueOut = self::secondsField($suggestion['cue_out']);

        $this->dispatch('notify', message: $suggestion['cue_in'] === null && $suggestion['cue_out'] === null
            ? __('No silence found at the start or end.')
            : __('Cue points suggested from silence. Check them and save.'), type: 'info');
    }

    /** Seconds for an input field: two decimals at most, empty when not set. */
    private static function secondsField(?float $seconds): string
    {
        return $seconds === null ? '' : rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.');
    }

    /**
     * The cue and fade fields as they are stored, checked against each other and the file
     * length. Values that mean "nothing to do" (cue in at 0, cue out at the very end, a
     * fade of 0 s) are stored as null, so an untouched file stays plainly untouched.
     *
     * @return array{cue_in_seconds: ?float, cue_out_seconds: ?float, fade_in_seconds: ?float, fade_out_seconds: ?float}
     */
    private function validatedCuePoints(): array
    {
        $parse = fn (string $value): ?float => trim($value) === '' ? null : round((float) $value, 2);

        $cueIn = $parse($this->cueIn);
        $cueOut = $parse($this->cueOut);
        $fadeIn = $parse($this->fadeIn);
        $fadeOut = $parse($this->fadeOut);
        $length = $this->file->duration_seconds;

        $errors = [];

        if ($length !== null && $cueIn !== null && $cueIn >= $length) {
            $errors['cueIn'] = __('Cue in must lie within the file.');
        }

        // Until the waveform job measured the exact length, the stored one is rounded to whole
        // seconds, so allow up to one second more.
        if ($length !== null && $cueOut !== null && $cueOut > $length + 1) {
            $errors['cueOut'] = __('Cue out must lie within the file.');
        }

        if ($cueOut !== null && $cueOut <= ($cueIn ?? 0.0)) {
            $errors['cueOut'] = __('Cue out must lie after cue in.');
        }

        $playable = ($cueOut ?? $length) !== null ? ($cueOut ?? $length) - ($cueIn ?? 0.0) : null;

        if ($playable !== null && ($fadeIn ?? 0.0) + ($fadeOut ?? 0.0) > $playable) {
            $errors['fadeOut'] = __('Fade in and fade out together are longer than what plays.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'cue_in_seconds' => $cueIn !== null && $cueIn > 0.0 ? $cueIn : null,
            'cue_out_seconds' => $cueOut !== null && ($length === null || $cueOut < $length) ? $cueOut : null,
            'fade_in_seconds' => $fadeIn !== null && $fadeIn > 0.0 ? $fadeIn : null,
            'fade_out_seconds' => $fadeOut !== null && $fadeOut > 0.0 ? $fadeOut : null,
        ];
    }

    /** A datetime-local value as a whole minute, null when the field is empty. */
    private function parseRunTime(string $value): ?Carbon
    {
        return $value !== '' ? Carbon::createFromFormat('Y-m-d\TH:i', $value)->startOfMinute() : null;
    }

    /**
     * The windows as they are stored: weekdays as sorted integers, and no windows at
     * all turning into null so an ungated file stays plainly ungated in the database.
     *
     * @return array<int, array{days: array<int, int>, from: string, to: string}>|null
     */
    private function normalizedAirtimeWindows(): ?array
    {
        $windows = collect($this->airtimeWindows)
            ->map(function (array $window): array {
                $days = array_values(array_unique(array_map('intval', $window['days'] ?? [])));
                sort($days);

                return [
                    // All seven days mean the same as no restriction, so store none.
                    'days' => count($days) === 7 ? [] : $days,
                    'from' => $window['from'],
                    'to' => $window['to'],
                ];
            })
            ->values()
            ->all();

        return $windows === [] ? null : $windows;
    }

    /**
     * Called from Alpine as soon as the chunked upload of the replacement has finished.
     */
    public function addPendingReplacement(string $path, ?string $title, ?int $duration, string $clientName, ?string $artist = null, ?string $album = null): void
    {
        abort_unless($this->mayReplace, 403);

        // The path must belong to this tenant's library.
        if (! str_starts_with($path, "tenants/{$this->station->tenant_id}/media/")) {
            return;
        }

        $this->pendingReplacement = [
            'path' => $path,
            'filename' => $clientName,
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'duration' => $duration,
        ];
    }

    public function cancelReplacement(): void
    {
        $this->discardPendingReplacement();
        $this->reset('pendingReplacement', 'adoptMetadata');
    }

    /** Removes an uploaded but never adopted replacement from disk. */
    private function discardPendingReplacement(): void
    {
        if ($this->pendingReplacement) {
            Storage::disk('local')->delete($this->pendingReplacement['path']);
        }
    }

    /**
     * Adopts the uploaded file as the new version. Rundowns that are already generated
     * keep their frozen version until they are generated again.
     */
    public function confirmReplacement(MediaReplacementService $replacer): void
    {
        abort_unless($this->mayReplace, 403);

        if (! $this->file || ! $this->pendingReplacement) {
            return;
        }

        $replacer->replace(
            $this->file,
            $this->pendingReplacement['path'],
            $this->pendingReplacement['filename'],
            auth()->user(),
            $this->adoptMetadata,
        );

        $this->file->refresh();
        $this->fillForm();
        $this->reset('pendingReplacement', 'adoptMetadata');
        unset($this->upcomingRundowns);

        $this->dispatch('media-library-changed');
        $this->dispatch('notify', message: __('File replaced. Regenerate the affected rundowns so the new version goes on air.'), type: 'success');
    }

    public function restoreVersion(int $versionId, MediaReplacementService $replacer): void
    {
        abort_unless($this->mayReplace, 403);

        if (! $this->file) {
            return;
        }

        $version = $this->file->versions()->findOrFail($versionId);

        $replacer->restore($version, auth()->user());

        $this->file->refresh();
        $this->fillForm();

        $this->dispatch('media-library-changed');
        $this->dispatch('notify', message: __('Previous version restored.'), type: 'success');
    }

    public function delete(): void
    {
        abort_unless($this->mayReplace, 403);

        if (! $this->file) {
            return;
        }

        Storage::disk('local')->delete($this->file->file_path);
        $this->file->delete();

        $this->close();

        $this->dispatch('media-library-changed');
        $this->dispatch('notify', message: __('File deleted.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.media-library.file-modal', [
            'tags' => $this->station->tags()->orderBy('name')->get(),
            'versions' => $this->file?->versions()->with('replacedBy')->get() ?? collect(),
        ]);
    }
}
