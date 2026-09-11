<?php

namespace App\Livewire\Playlist;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Support\PlaylistElements\ElementDraft;
use App\Support\PlaylistElements\ElementTypes;
use Illuminate\Support\Collection;
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

    /** @var array<int> External sources picked for the next add, in the order they were clicked. */
    public array $selectedExternalSourceIds = [];

    public string $externalSearch = '';

    public string $newDuration = '';

    public string $newRelativeOffset = ''; // Format MM:SS oder leer

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public ?int $selectedMediaFileId = null;

    public ?int $selectedContainerId = null;

    public string $librarySearch = '';

    // Fill-Optionen (neues Item)
    /** @var array<int|string> */
    public array $newFillTagIds = [];

    public string $newFillMaxDuration = '';

    /** @var array<int|string> Items ticked in the list, for the bulk actions. */
    public array $selectedItemIds = [];

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
        // Containers are never scheduled on their own: playback and start mode belong to
        // the playlist that embeds them, so only the name is editable here.
        if ($this->playlist->isContainer()) {
            $this->validate(['name' => 'required|string|min:2|max:80']);
            $this->playlist->update(['name' => $this->name]);
            $this->dispatch('notify', message: __('Einstellungen gespeichert.'), type: 'success');

            return;
        }

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

    /**
     * Picks an external source for the next add, or drops it again. The click order is
     * kept, so the operator decides in which order a block of elements lands.
     */
    public function toggleExternalSource(int $sourceId): void
    {
        $this->selectedExternalSourceIds = in_array($sourceId, $this->selectedExternalSourceIds, true)
            ? array_values(array_diff($this->selectedExternalSourceIds, [$sourceId]))
            : [...$this->selectedExternalSourceIds, $sourceId];
    }

    public function addItem(): void
    {
        $element = ElementTypes::for($this->newType, $this->addMode);
        $rules = $element->rules();

        // Types like the ad break are configured by their position alone and validate nothing.
        if ($rules !== []) {
            $this->validate($rules);
        }

        $added = $element->create(
            $this->playlist,
            $this->draft(),
            $this->playlist->items()->max('position') + 1,
        );

        $this->reset('newTitle', 'newUrl', 'selectedExternalSourceIds', 'externalSearch', 'newDuration',
            'newFile', 'showAddForm', 'selectedMediaFileId', 'selectedContainerId', 'librarySearch',
            'newFillTagIds', 'newFillMaxDuration', 'newRelativeOffset');
        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element hinzugefügt.|[2,*]:count Elemente hinzugefügt.',
            $added,
            ['count' => $added],
        ));
    }

    /** Collects the add form into one object for the element type to read. */
    private function draft(): ElementDraft
    {
        return new ElementDraft(
            type: $this->newType,
            title: $this->newTitle,
            url: $this->newUrl,
            durationSeconds: $this->newDuration !== '' ? (int) $this->newDuration : null,
            mediaFileId: $this->selectedMediaFileId,
            externalSourceIds: array_map('intval', $this->selectedExternalSourceIds),
            containerId: $this->selectedContainerId,
            tagIds: $this->newFillTagIds,
            fillMaxDurationSeconds: $this->newFillMaxDuration !== '' ? (int) $this->newFillMaxDuration : null,
            relativeOffsetSeconds: $this->parseOffset($this->newRelativeOffset),
            upload: $this->newFile,
        );
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
        $this->deleteItems($this->playlist->items()->where('id', $itemId)->get());
    }

    /**
     * Copies one element and puts the copy right behind the original, so a block that is
     * needed several times does not have to be searched for again.
     */
    public function duplicateItem(int $itemId): void
    {
        $copies = $this->copyItems($this->playlist->items()->where('id', $itemId)->get());

        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element duplicated.|[2,*]:count elements duplicated.',
            $copies,
            ['count' => $copies],
        ));
    }

    public function duplicateSelected(): void
    {
        $copies = $this->copyItems($this->selectedItems());

        $this->reset('selectedItemIds');
        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element duplicated.|[2,*]:count elements duplicated.',
            $copies,
            ['count' => $copies],
        ));
    }

    public function removeSelected(): void
    {
        $removed = $this->deleteItems($this->selectedItems());

        $this->reset('selectedItemIds');
        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element removed.|[2,*]:count elements removed.',
            $removed,
            ['count' => $removed],
        ));
    }

    public function selectAllItems(): void
    {
        $this->selectedItemIds = $this->playlist->items()->pluck('id')->map(strval(...))->all();
    }

    public function clearSelection(): void
    {
        $this->reset('selectedItemIds');
    }

    /** @return Collection<int, PlaylistItem> the ticked items, in playlist order */
    private function selectedItems(): Collection
    {
        return $this->playlist->items()
            ->whereIn('id', array_map('intval', $this->selectedItemIds))
            ->orderBy('position')
            ->get();
    }

    /**
     * @param  Collection<int, PlaylistItem>  $items
     * @return int how many copies were made
     */
    private function copyItems(Collection $items): int
    {
        if ($items->isEmpty()) {
            return 0;
        }

        $copyAfter = $items->pluck('id')->all();
        $position = 0;
        $copies = 0;

        foreach ($this->playlist->items()->orderBy('position')->get() as $item) {
            $item->update(['position' => $position++]);

            if (in_array($item->id, $copyAfter, true)) {
                $copy = $item->replicate();
                $copy->position = $position++;
                $copy->save();
                $copies++;
            }
        }

        return $copies;
    }

    /**
     * @param  Collection<int, PlaylistItem>  $items
     * @return int how many items were removed
     */
    private function deleteItems(Collection $items): int
    {
        foreach ($items as $item) {
            // Direkten file_path löschen (Legacy, ohne MediaFile-Referenz). Eine Kopie des
            // Elements zeigt auf dieselbe Datei - die darf dann nicht verschwinden.
            $stillInUse = $item->file_path && PlaylistItem::where('file_path', $item->file_path)
                ->whereNot('id', $item->id)
                ->exists();

            if ($item->file_path && ! $item->media_file_id && ! $stillInUse) {
                Storage::disk('local')->delete($item->file_path);
            }

            $item->delete();
        }

        $this->resequence();

        return $items->count();
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

        if ($this->showAddForm && $this->addMode === 'library' && ElementTypes::needsMediaFile($this->newType)) {
            $query = $this->playlist->station->mediaFiles()
                ->where('type', $this->newType);

            // Die Suchbedingungen gehoeren geklammert, sonst hebt das orWhere den
            // Typfilter auf und die Jingle-Liste zeigt ploetzlich Musiktitel.
            if ($this->librarySearch !== '') {
                $query->where(fn ($sub) => $sub
                    ->where('title', 'like', '%'.$this->librarySearch.'%')
                    ->orWhere('artist', 'like', '%'.$this->librarySearch.'%')
                    ->orWhere('id', 'like', '%'.$this->librarySearch.'%'));
            }

            $libraryFiles = $query->orderBy('title')->get();
        }

        return view('livewire.playlist.manager', [
            'items' => $this->playlist->items()->with(['mediaFile', 'externalSource', 'containerPlaylist'])->orderBy('position')->get(),
            'containers' => $this->playlist->isContainer()
                ? collect()
                : $this->playlist->station->playlists()->containers()->withCount('items')->orderBy('name')->get(),
            'libraryFiles' => $libraryFiles,
            'selectableTypes' => ElementTypes::selectableLabels(! $this->playlist->isContainer()),
            'needsMediaFile' => ElementTypes::needsMediaFile($this->newType),
            'supportsTimestamp' => ElementTypes::supportsTimestamp($this->newType),
            'stationTags' => $this->playlist->station->tags()->orderBy('name')->get(),
            'externalSources' => $this->playlist->station->externalSources()
                ->when($this->externalSearch !== '', fn ($query) => $query
                    ->where(fn ($sub) => $sub
                        ->where('name', 'like', '%'.$this->externalSearch.'%')
                        ->orWhere('broadcast_title', 'like', '%'.$this->externalSearch.'%')))
                ->orderBy('name')
                ->get(),
            'hasExternalSources' => $this->playlist->station->externalSources()->exists(),
        ])->layout('layouts.app');
    }
}
