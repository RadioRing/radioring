<?php

namespace App\Livewire\Playlist;

use App\Models\MediaFile;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Playlist bearbeiten')]
class Manager extends Component
{
    use WithFileUploads;

    #[Locked]
    public Playlist $playlist;

    // Playlist-Einstellungen
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|in:sequential,random')]
    public string $playbackMode = 'sequential';

    #[Validate('required|in:soft,hard')]
    public string $startMode = 'soft';

    // Neues Item
    public bool $showAddForm = false;

    public string $addMode = 'library'; // 'library' oder 'upload'

    public string $newType = 'music';

    public string $newTitle = '';

    public string $newUrl = '';

    public ?int $newExternalSourceId = null;

    public string $newDuration = '';

    public string $newRelativeOffset = ''; // Format MM:SS oder leer

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public ?int $selectedMediaFileId = null;

    public string $librarySearch = '';

    // Fill-Optionen (neues Item)
    /** @var array<int|string> */
    public array $newFillTagIds = [];

    public string $newFillMaxDuration = '';

    // Item bearbeiten
    public ?int $editingItemId = null;

    public string $editRelativeOffset = '';

    /** @var array<int|string> */
    public array $editFillTagIds = [];

    public string $editFillMaxDuration = '';

    public function mount(Playlist $playlist): void
    {
        $station = auth()->user()->currentStation();
        abort_unless($station && $playlist->station_id === $station->id, 403);

        $this->playlist = $playlist;
        $this->name = $playlist->name;
        $this->playbackMode = $playlist->playback_mode;
        $this->startMode = $playlist->start_mode ?? 'soft';
    }

    public function saveSettings(): void
    {
        $this->validate([
            'name' => 'required|string|min:2|max:80',
            'playbackMode' => 'required|in:sequential,random',
            'startMode' => 'required|in:soft,hard',
        ]);

        $this->playlist->update([
            'name' => $this->name,
            'playback_mode' => $this->playbackMode,
            'start_mode' => $this->startMode,
        ]);

        $this->dispatch('notify', message: __('Einstellungen gespeichert.'), type: 'success');
    }

    public function addItem(): void
    {
        $nextPosition = $this->playlist->items()->max('position') + 1;

        if ($this->newType === 'adbreak') {
            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => 'adbreak',
                'title' => 'Werbeunterbrechung (START_AD_BREAK)',
            ]);
        } elseif ($this->newType === 'random') {
            $validTagIds = $this->playlist->station->tags()->pluck('id')->all();
            $fillTagIds = array_values(array_intersect(array_map('intval', $this->newFillTagIds), $validTagIds));

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => 'random',
                'title' => 'Zufälliges Element',
                'fill_tags' => $fillTagIds ?: null,
            ]);
        } elseif ($this->newType === 'fill') {
            $this->validate([
                'newFillMaxDuration' => 'nullable|integer|min:60|max:7200',
            ]);

            $validTagIds = $this->playlist->station->tags()->pluck('id')->all();
            $fillTagIds = array_values(array_intersect(array_map('intval', $this->newFillTagIds), $validTagIds));

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => 'fill',
                'title' => 'Auffüllen mit Musik',
                'fill_tags' => $fillTagIds ?: null,
                'fill_max_duration_seconds' => $this->newFillMaxDuration ? (int) $this->newFillMaxDuration : null,
            ]);
        } elseif ($this->newType === 'url') {
            $this->validate([
                'newTitle' => 'required|string|min:1|max:200',
                'newUrl' => 'required|url|max:2048',
                'newDuration' => 'nullable|integer|min:1|max:86400',
            ]);

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => 'url',
                'title' => $this->newTitle,
                'url' => $this->newUrl,
                'duration_seconds' => $this->newDuration ? (int) $this->newDuration : null,
                'relative_offset_seconds' => $this->parseOffset($this->newRelativeOffset),
            ]);
        } elseif ($this->newType === 'external') {
            $this->validate([
                'newExternalSourceId' => 'required|integer',
            ]);

            $source = $this->playlist->station->externalSources()->findOrFail($this->newExternalSourceId);

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => 'external',
                'title' => $source->name,
                'external_source_id' => $source->id,
                // Kein Dauer-Snapshot: dynamische Quelle, die Länge wird bei der
                // Rundown-Generierung aus der aktuellen erwarteten Dauer gezogen.
                'duration_seconds' => null,
                'relative_offset_seconds' => $this->parseOffset($this->newRelativeOffset),
            ]);
        } elseif ($this->addMode === 'library') {
            $this->validate([
                'selectedMediaFileId' => 'required|integer',
            ]);

            $mediaFile = $this->playlist->station->mediaFiles()
                ->findOrFail($this->selectedMediaFileId);

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => $mediaFile->type,
                'title' => $mediaFile->title,
                'media_file_id' => $mediaFile->id,
                'relative_offset_seconds' => $this->parseOffset($this->newRelativeOffset),
            ]);
        } else {
            $this->validate([
                'newTitle' => 'required|string|min:1|max:200',
                'newFile' => 'required|file|mimes:mp3,m4a,ogg,wav,flac|max:307200',
            ]);

            $slug = $this->playlist->station->slug;
            $filePath = $this->newFile->storeAs(
                "stations/{$slug}/media",
                $this->newFile->getClientOriginalName(),
                'local'
            );

            // Datei auch in Bibliothek speichern
            $mediaFile = $this->playlist->station->mediaFiles()->create([
                'title' => $this->newTitle,
                'type' => $this->newType,
                'file_path' => $filePath,
            ]);

            $this->playlist->items()->create([
                'position' => $nextPosition,
                'type' => $this->newType,
                'title' => $this->newTitle,
                'media_file_id' => $mediaFile->id,
                'relative_offset_seconds' => $this->parseOffset($this->newRelativeOffset),
            ]);
        }

        $this->reset('newTitle', 'newUrl', 'newExternalSourceId', 'newDuration', 'newFile', 'showAddForm',
            'selectedMediaFileId', 'librarySearch', 'newFillTagIds', 'newFillMaxDuration',
            'newRelativeOffset');
        $this->dispatch('notify', message: __('Element hinzugefügt.'), type: 'success');
    }

    public function startEditingItem(int $itemId): void
    {
        $item = $this->playlist->items()->findOrFail($itemId);
        $this->editingItemId = $itemId;
        $this->editRelativeOffset = $item->relative_offset_seconds !== null
            ? $this->formatOffset($item->relative_offset_seconds)
            : '';
        $this->editFillTagIds = $item->fill_tags
            ? array_map('strval', $item->fill_tags)
            : [];
        $this->editFillMaxDuration = $item->fill_max_duration_seconds
            ? (string) $item->fill_max_duration_seconds
            : '';
    }

    public function saveItem(): void
    {
        $item = $this->playlist->items()->findOrFail($this->editingItemId);

        if ($item->type === 'fill') {
            $this->validate([
                'editFillMaxDuration' => 'nullable|integer|min:60|max:7200',
            ]);

            $validTagIds = $this->playlist->station->tags()->pluck('id')->all();
            $fillTagIds = array_values(array_intersect(array_map('intval', $this->editFillTagIds), $validTagIds));

            $item->update([
                'fill_tags' => $fillTagIds ?: null,
                'fill_max_duration_seconds' => $this->editFillMaxDuration ? (int) $this->editFillMaxDuration : null,
            ]);
        } elseif ($item->type === 'random') {
            $validTagIds = $this->playlist->station->tags()->pluck('id')->all();
            $fillTagIds = array_values(array_intersect(array_map('intval', $this->editFillTagIds), $validTagIds));

            $item->update([
                'fill_tags' => $fillTagIds ?: null,
            ]);
        } else {
            $item->update([
                'relative_offset_seconds' => $this->parseOffset($this->editRelativeOffset),
            ]);
        }

        $this->editingItemId = null;
        $this->dispatch('notify', message: __('Element gespeichert.'), type: 'success');
    }

    public function cancelEditingItem(): void
    {
        $this->editingItemId = null;
    }

    public function removeItem(int $itemId): void
    {
        $item = $this->playlist->items()->findOrFail($itemId);

        // Direkten file_path löschen (Legacy, ohne MediaFile-Referenz)
        if ($item->file_path && ! $item->media_file_id) {
            Storage::disk('local')->delete($item->file_path);
        }

        $item->delete();
        $this->resequence();
    }

    /**
     * Wird vom SortableJS-Handler aufgerufen.
     *
     * @param  array<int>  $ids  Geordnete Item-IDs
     */
    public function reorder(array $ids): void
    {
        foreach ($ids as $position => $id) {
            $this->playlist->items()->where('id', $id)->update(['position' => $position]);
        }
    }

    /**
     * Wandelt eine MM:SS-Eingabe oder reine Sekundenangabe in Sekunden um.
     */
    private function parseOffset(string $offset): ?int
    {
        $offset = trim($offset);

        if ($offset === '') {
            return null;
        }

        if (str_contains($offset, ':')) {
            [$min, $sec] = array_pad(explode(':', $offset, 2), 2, '0');

            return (int) $min * 60 + (int) $sec;
        }

        return (int) $offset;
    }

    /**
     * Formatiert Sekunden als MM:SS für die Anzeige.
     */
    public function formatOffset(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function resequence(): void
    {
        $this->playlist->items()->orderBy('position')->get()
            ->each(function (PlaylistItem $item, int $index) {
                $item->update(['position' => $index]);
            });
    }

    public function render()
    {
        $libraryFiles = collect();

        if ($this->showAddForm && $this->addMode === 'library' && ! in_array($this->newType, ['url', 'external', 'fill', 'adbreak', 'random'])) {
            $query = $this->playlist->station->mediaFiles()
                ->where('type', $this->newType);

            if ($this->librarySearch) {
                $query->where('title', 'like', '%'.$this->librarySearch.'%')->orWhere('id', 'like', '%'.$this->librarySearch.'%');
            }

            $libraryFiles = $query->orderBy('title')->get();
        }

        return view('livewire.playlist.manager', [
            'items' => $this->playlist->items()->with(['mediaFile', 'externalSource'])->orderBy('position')->get(),
            'libraryFiles' => $libraryFiles,
            'stationTags' => $this->playlist->station->tags()->orderBy('name')->get(),
            'externalSources' => $this->playlist->station->externalSources()->orderBy('name')->get(),
        ])->layout('layouts.app');
    }
}
