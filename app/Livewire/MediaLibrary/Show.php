<?php

namespace App\Livewire\MediaLibrary;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\MediaFile;
use App\Models\PlaylistItem;
use App\Models\Station;
use App\Services\MediaReplacementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Media file')]
class Show extends Component
{
    #[Locked]
    public Station $station;

    #[Locked]
    public MediaFile $file;

    public string $title = '';

    public string $artist = '';

    public string $album = '';

    public string $notes = '';

    public string $type = 'music';

    public bool $fadeIn = false;

    /** @var array<int|string> */
    public array $tagIds = [];

    // Ersetzen: fertig hochgeladene Datei, die noch nicht übernommen wurde
    public bool $showReplaceForm = false;

    /** @var array{path: string, filename: string, title: ?string, artist: ?string, album: ?string, duration: ?int}|null */
    public ?array $pendingReplacement = null;

    public bool $adoptMetadata = false;

    public function mount(MediaFile $mediaFile): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, __('No station selected.'));

        abort_unless($this->station->canUseMedia($mediaFile), 404);

        $this->file = $mediaFile;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->title = $this->file->title;
        $this->artist = $this->file->artist ?? '';
        $this->album = $this->file->album ?? '';
        $this->notes = $this->file->notes ?? '';
        $this->type = $this->file->type;
        $this->fadeIn = $this->file->fade_in;
        $this->tagIds = $this->file->tags()->pluck('tags.id')->map(fn ($id) => (string) $id)->all();
    }

    #[Computed]
    public function mayWrite(): bool
    {
        return auth()->user()->mayWriteMediaOn($this->station);
    }

    /**
     * Löschen und Ersetzen wirken auf jede Station des Mandanten und bleiben deshalb
     * beim Inhaber – ein Editor könnte sonst still den Inhalt einer Datei tauschen,
     * die andere Stationen eingeplant haben.
     */
    #[Computed]
    public function mayReplace(): bool
    {
        return auth()->user()->mayDeleteMediaOn($this->station);
    }

    /**
     * Playlisten, in denen die Datei fest eingeplant ist.
     *
     * @return Collection<int, PlaylistItem>
     */
    #[Computed]
    public function usages(): Collection
    {
        return $this->file->playlistItems()
            ->with('playlist')
            ->get()
            ->filter(fn (PlaylistItem $item) => $item->playlist !== null)
            ->sortBy(fn (PlaylistItem $item) => $item->playlist->name)
            ->values();
    }

    /**
     * Bereits generierte, noch nicht gesendete Rundowns dieser Station mit der Datei.
     * Sie halten einen Pfad-Snapshot und spielen nach einem Ersetzen weiter die alte
     * Fassung, bis sie neu generiert werden.
     *
     * @return Collection<int, GeneratedPlaylist>
     */
    #[Computed]
    public function upcomingRundowns(): Collection
    {
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

    public function save(): void
    {
        abort_unless($this->mayWrite, 403);

        $this->validate([
            'title' => 'required|string|min:1|max:200',
            'artist' => 'nullable|string|max:200',
            'album' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
            'type' => 'required|in:music,jingle',
            'fadeIn' => 'boolean',
        ]);

        $this->file->update([
            'title' => trim($this->title),
            'artist' => trim($this->artist) !== '' ? trim($this->artist) : null,
            'album' => trim($this->album) !== '' ? trim($this->album) : null,
            'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            'type' => $this->type,
            'fade_in' => $this->fadeIn,
        ]);

        $tenantTagIds = $this->station->tags()->pluck('id')->all();
        $this->file->tags()->sync(array_intersect(array_map('intval', $this->tagIds), $tenantTagIds));

        $this->dispatch('notify', message: __('Metadata saved.'), type: 'success');
    }

    /**
     * Wird von Alpine aufgerufen, sobald der Chunk-Upload der Ersatzdatei fertig ist.
     */
    public function addPendingReplacement(string $path, ?string $title, ?int $duration, string $clientName, ?string $artist = null, ?string $album = null): void
    {
        abort_unless($this->mayReplace, 403);

        // Der Pfad muss in der Bibliothek dieses Mandanten liegen.
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
        if ($this->pendingReplacement) {
            Storage::disk('local')->delete($this->pendingReplacement['path']);
        }

        $this->reset('pendingReplacement', 'showReplaceForm', 'adoptMetadata');
    }

    /**
     * Übernimmt die hochgeladene Datei als neue Fassung. Bereits generierte Rundowns
     * behalten ihre eingefrorene Fassung, bis sie neu generiert werden.
     */
    public function confirmReplacement(MediaReplacementService $replacer): void
    {
        abort_unless($this->mayReplace, 403);

        if (! $this->pendingReplacement) {
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
        $this->reset('pendingReplacement', 'showReplaceForm', 'adoptMetadata');
        unset($this->upcomingRundowns);

        $this->dispatch('notify', message: __('File replaced. Regenerate the affected rundowns so the new version goes on air.'), type: 'success');
    }

    public function restoreVersion(int $versionId, MediaReplacementService $replacer): void
    {
        abort_unless($this->mayReplace, 403);

        $version = $this->file->versions()->findOrFail($versionId);

        $replacer->restore($version, auth()->user());

        $this->file->refresh();
        $this->fillForm();

        $this->dispatch('notify', message: __('Previous version restored.'), type: 'success');
    }

    public function delete()
    {
        abort_unless($this->mayReplace, 403);

        Storage::disk('local')->delete($this->file->file_path);
        $this->file->delete();

        return $this->redirectRoute('media.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.media-library.show', [
            'tags' => $this->station->tags()->orderBy('name')->get(),
            'versions' => $this->file->versions()->with('replacedBy')->get(),
        ])->layout('layouts.app');
    }
}
