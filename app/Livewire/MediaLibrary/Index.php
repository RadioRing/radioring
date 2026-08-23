<?php

namespace App\Livewire\MediaLibrary;

use App\Jobs\AnalyzeMediaLoudnessJob;
use App\Models\MediaFile;
use App\Models\PlaylistItem;
use App\Models\Station;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Media library')]
class Index extends Component
{
    #[Locked]
    public Station $station;

    public string $filterType = '';

    public string $filterTagId = '';

    public string $search = '';

    /** Nur Dateien anzeigen, die als (Metadaten-)Duplikat erkannt wurden. */
    public bool $filterDuplicates = false;

    // Upload
    public bool $showUploadForm = false;

    /**
     * Serverseitig fertig assemblierte Dateien, die noch nicht in der DB gespeichert sind.
     *
     * @var array<array{path: string, title: string, artist: ?string, type: string, duration: ?int, clientName: string}>
     */
    public array $pendingUploads = [];

    // Metadaten-Bearbeitung (Titel/Interpret/Album) einer bestehenden Datei
    public ?int $editingFileId = null;

    public string $editTitle = '';

    public string $editArtist = '';

    public string $editAlbum = '';

    public bool $editFadeIn = false;

    // Tag-Verwaltung
    public bool $showTagManager = false;

    public string $newTagName = '';

    public ?int $editingTagsForFileId = null;

    /** @var array<int> */
    public array $editingTagIds = [];

    // Massen-Auswahl
    /** @var array<int|string> */
    public array $selectedFileIds = [];

    public string $bulkTagId = '';

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, __('No station selected.'));
    }

    /**
     * May the current user add to or edit the tenant library through this station?
     * Owners and editors may; see User::mayWriteMediaOn().
     */
    #[Computed]
    public function mayWrite(): bool
    {
        return auth()->user()->mayWriteMediaOn($this->station);
    }

    /**
     * Deleting reaches every station of the tenant, so it stays with the owner.
     */
    #[Computed]
    public function mayDelete(): bool
    {
        return auth()->user()->mayDeleteMediaOn($this->station);
    }

    /**
     * Normalisiert Interpret + Titel zu einem Vergleichsschlüssel für die Duplikat-Erkennung:
     * kleingeschrieben, ohne Rand-Leerzeichen und mit zusammengefassten Leerzeichen.
     */
    private function duplicateKey(?string $artist, string $title): string
    {
        $normalize = fn (string $value): string => preg_replace('/\s+/', ' ', trim(Str::lower($value)));

        return $normalize($artist ?? '').'|'.$normalize($title);
    }

    /**
     * IDs eigener Dateien, die nach normalisiertem Interpret + Titel mit mindestens
     * einer weiteren eigenen Datei übereinstimmen – also vermutlich doppelt hochgeladen.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function duplicateFileIds(): array
    {
        return $this->station->mediaFiles()
            ->get(['id', 'title', 'artist'])
            ->groupBy(fn (MediaFile $file) => $this->duplicateKey($file->artist, $file->title))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->flatMap(fn (Collection $group) => $group->pluck('id'))
            ->all();
    }

    /**
     * Womit die Fill- und Zufalls-Elemente der Playlisten dieser Station gefuellt werden.
     *
     * Solche Elemente greifen erst bei der Rundown-Generierung auf die Bibliothek zu:
     * Eine Datei kann also gesendet werden, ohne in einer Playlist zu stehen. Fill nimmt
     * nur Musik, Zufall jeden Typ; ohne Tag-Filter kommt jeweils die ganze Bibliothek
     * in Frage.
     *
     * @return array{music: bool, any: bool, musicTagIds: array<int, int>, anyTagIds: array<int, int>}
     */
    #[Computed]
    public function fillPools(): array
    {
        $items = PlaylistItem::whereIn('type', ['fill', 'random'])
            ->whereHas('playlist', fn ($q) => $q->where('station_id', $this->station->id))
            ->get(['type', 'fill_tags']);

        $pools = ['music' => false, 'any' => false, 'musicTagIds' => [], 'anyTagIds' => []];

        foreach ($items as $item) {
            $tagIds = array_map('intval', $item->fill_tags ?? []);
            $scope = $item->type === 'fill' ? 'music' : 'any';

            if (empty($tagIds)) {
                $pools[$scope] = true;

                continue;
            }

            $key = $scope === 'music' ? 'musicTagIds' : 'anyTagIds';
            $pools[$key] = array_values(array_unique([...$pools[$key], ...$tagIds]));
        }

        return $pools;
    }

    /**
     * Kann diese Datei ueber ein Fill-/Zufalls-Element auf Sendung gehen, auch wenn sie
     * in keiner Playlist fest eingeplant ist?
     */
    public function isReachableByFill(MediaFile $file): bool
    {
        $pools = $this->fillPools;
        $tagIds = $file->tags->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($pools['any'] || array_intersect($tagIds, $pools['anyTagIds'])) {
            return true;
        }

        if ($file->type !== 'music') {
            return false;
        }

        return $pools['music'] || (bool) array_intersect($tagIds, $pools['musicTagIds']);
    }

    /**
     * Wird von Alpine aufgerufen, wenn ein Chunk-Upload abgeschlossen ist.
     * Der Pfad ist bereits auf dem Server gespeichert.
     */
    public function addPendingUpload(string $path, ?string $title, ?int $duration, string $clientName, ?string $artist = null, ?string $album = null): void
    {
        abort_unless($this->mayWrite, 403);

        // The path must belong to this tenant's library.
        $expectedPrefix = "tenants/{$this->station->tenant_id}/media/";
        if (! str_starts_with($path, $expectedPrefix)) {
            return;
        }

        $this->pendingUploads[] = [
            'path' => $path,
            'title' => $title ?: pathinfo($clientName, PATHINFO_FILENAME),
            'artist' => $artist ?: '',
            'album' => $album ?: '',
            'type' => 'music',
            'duration' => $duration,
            'clientName' => $clientName,
        ];
    }

    public function removePending(int $index): void
    {
        if (isset($this->pendingUploads[$index]['path'])) {
            Storage::disk('local')->delete($this->pendingUploads[$index]['path']);
        }

        array_splice($this->pendingUploads, $index, 1);
    }

    public function save(): void
    {
        abort_unless($this->mayWrite, 403);

        if (empty($this->pendingUploads)) {
            return;
        }

        $this->validate([
            'pendingUploads.*.title' => 'required|string|min:1|max:200',
            'pendingUploads.*.artist' => 'nullable|string|max:200',
            'pendingUploads.*.album' => 'nullable|string|max:200',
            'pendingUploads.*.type' => 'required|in:music,jingle',
        ]);

        $count = 0;

        foreach ($this->pendingUploads as $upload) {
            $file = $this->station->mediaFiles()->create([
                'title' => $upload['title'],
                'artist' => $upload['artist'] !== '' ? $upload['artist'] : null,
                'album' => ($upload['album'] ?? '') !== '' ? $upload['album'] : null,
                'type' => $upload['type'],
                'file_path' => $upload['path'],
                'duration_seconds' => $upload['duration'],
            ]);

            // Lautheit offline (per ffmpeg) messen – einmalig, asynchron.
            AnalyzeMediaLoudnessJob::dispatch($file->id);

            $count++;
        }

        $this->reset('pendingUploads', 'showUploadForm');
        $this->dispatch('notify', message: __(':n file(s) uploaded.', ['n' => $count]), type: 'success');
    }

    public function cancelUpload(): void
    {
        foreach ($this->pendingUploads as $upload) {
            Storage::disk('local')->delete($upload['path']);
        }

        $this->reset('pendingUploads', 'showUploadForm');
    }

    /**
     * Deleting removes the file from every station of the tenant, which is why it is
     * restricted to owners.
     */
    public function delete(int $mediaFileId): void
    {
        abort_unless($this->mayDelete, 403);

        $file = $this->station->mediaFiles()->findOrFail($mediaFileId);

        Storage::disk('local')->delete($file->file_path);
        $file->delete();

        $this->dispatch('notify', message: __('File deleted.'), type: 'success');
    }

    public function startEditingFile(int $fileId): void
    {
        abort_unless($this->mayWrite, 403);

        $file = $this->station->mediaFiles()->findOrFail($fileId);

        $this->editingFileId = $file->id;
        $this->editTitle = $file->title;
        $this->editArtist = $file->artist ?? '';
        $this->editAlbum = $file->album ?? '';
        $this->editFadeIn = $file->fade_in;
    }

    public function saveFileEdit(): void
    {
        if (! $this->editingFileId) {
            return;
        }

        abort_unless($this->mayWrite, 403);

        $this->validate([
            'editTitle' => 'required|string|min:1|max:200',
            'editArtist' => 'nullable|string|max:200',
            'editAlbum' => 'nullable|string|max:200',
            'editFadeIn' => 'boolean',
        ]);

        $file = $this->station->mediaFiles()->findOrFail($this->editingFileId);

        $file->update([
            'title' => trim($this->editTitle),
            'artist' => trim($this->editArtist) !== '' ? trim($this->editArtist) : null,
            'album' => trim($this->editAlbum) !== '' ? trim($this->editAlbum) : null,
            'fade_in' => $this->editFadeIn,
        ]);

        $this->cancelEditingFile();
        $this->dispatch('notify', message: __('Metadata saved.'), type: 'success');
    }

    public function cancelEditingFile(): void
    {
        $this->editingFileId = null;
        $this->editTitle = '';
        $this->editArtist = '';
        $this->editAlbum = '';
        $this->editFadeIn = false;
    }

    public function createTag(): void
    {
        abort_unless($this->mayWrite, 403);

        $this->validate([
            'newTagName' => 'required|string|min:1|max:50',
        ]);

        $this->station->tags()->firstOrCreate(['name' => trim($this->newTagName)]);
        $this->reset('newTagName');
    }

    public function deleteTag(int $tagId): void
    {
        abort_unless($this->mayDelete, 403);

        $this->station->tags()->findOrFail($tagId)->delete();
    }

    public function startEditingTags(int $fileId): void
    {
        abort_unless($this->mayWrite, 403);

        $file = $this->station->poolMediaFiles()->with('tags')->findOrFail($fileId);

        $this->editingTagsForFileId = $fileId;
        $this->editingTagIds = $file->tags
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function saveFileTags(): void
    {
        abort_unless($this->mayWrite, 403);

        if (! $this->editingTagsForFileId) {
            return;
        }

        $file = $this->station->poolMediaFiles()->findOrFail($this->editingTagsForFileId);

        // Tags and files now share one tenant scope, so a plain sync is enough: there
        // are no foreign tags left to preserve.
        $tenantTagIds = $this->station->tags()->pluck('id')->all();
        $selected = array_intersect(array_map('intval', $this->editingTagIds), $tenantTagIds);

        $file->tags()->sync($selected);

        $this->editingTagsForFileId = null;
        $this->editingTagIds = [];
    }

    public function cancelEditingTags(): void
    {
        $this->editingTagsForFileId = null;
        $this->editingTagIds = [];
    }

    /**
     * Wählt alle aktuell sichtbaren Dateien aus bzw. hebt die Auswahl auf,
     * wenn bereits alle sichtbaren ausgewählt sind.
     */
    public function toggleSelectAll(): void
    {
        $visibleIds = $this->filteredFiles()->pluck('id')->all();
        $selected = array_map('intval', $this->selectedFileIds);

        if (empty(array_diff($visibleIds, $selected))) {
            $this->selectedFileIds = array_values(array_diff($selected, $visibleIds));
        } else {
            $this->selectedFileIds = array_values(array_unique(array_merge($selected, $visibleIds)));
        }
    }

    public function clearSelection(): void
    {
        $this->reset('selectedFileIds', 'bulkTagId');
    }

    public function bulkAddTag(): void
    {
        $this->applyBulkTag(attach: true);
    }

    public function bulkRemoveTag(): void
    {
        $this->applyBulkTag(attach: false);
    }

    private function applyBulkTag(bool $attach): void
    {
        abort_unless($this->mayWrite, 403);

        $this->validate([
            'bulkTagId' => 'required',
        ], [
            'bulkTagId.required' => __('Please pick a tag first.'),
        ]);

        if (empty($this->selectedFileIds)) {
            return;
        }

        $tag = $this->station->tags()->find($this->bulkTagId);

        if (! $tag) {
            return;
        }

        $fileIds = $this->station->poolMediaFiles()
            ->whereIn('id', array_map('intval', $this->selectedFileIds))
            ->pluck('id')
            ->all();

        if ($attach) {
            $tag->mediaFiles()->syncWithoutDetaching($fileIds);
        } else {
            $tag->mediaFiles()->detach($fileIds);
        }

        $count = count($fileIds);
        $this->dispatch('notify', message: $attach
            ? __('Tag ":name" added to :n file(s).', ['name' => $tag->name, 'n' => $count])
            : __('Tag ":name" removed from :n file(s).', ['name' => $tag->name, 'n' => $count]),
            type: 'success');
    }

    /**
     * The tenant library, narrowed by the active filters and search.
     *
     * @return Collection<int, MediaFile>
     */
    private function filteredFiles()
    {
        $query = $this->station->poolMediaFiles()
            ->withCount('playlistItems')
            ->with('tags');

        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }

        if ($this->filterTagId === 'none') {
            $query->whereDoesntHave('tags');
        } elseif ($this->filterTagId) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $this->filterTagId));
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('artist', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->filterDuplicates) {
            $query->whereIn('id', $this->duplicateFileIds ?: [0]);
        }

        // Ordered by artist/title so duplicates end up next to each other.
        return $query->orderBy('artist')->orderBy('title')->get();
    }

    public function render()
    {
        return view('livewire.media-library.index', [
            'files' => $this->filteredFiles(),
            'tags' => $this->station->tags()->orderBy('name')->get(),
            'duplicateIds' => $this->duplicateFileIds,
        ])->layout('layouts.app');
    }
}
