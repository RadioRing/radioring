<?php

namespace App\Livewire\Playlist;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Support\PlaylistElements\ElementDraft;
use App\Support\PlaylistElements\ElementTypes;
use App\Support\PlaylistElements\PlaylistPalette;
use App\Support\PlaylistElements\PlaylistRuntime;
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

    /** How many palette entries are shown before "load more" appears. */
    private const PALETTE_PAGE = 40;

    #[Locked]
    public Playlist $playlist;

    // Playlist-Einstellungen
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|in:sequential,random')]
    public string $playbackMode = 'sequential';

    #[Validate('required|in:soft,hard')]
    public string $startMode = 'soft';

    public bool $showSettings = false;

    // Palette
    public string $paletteTab = PlaylistPalette::TAB_MEDIA;

    public string $paletteSearch = '';

    public string $paletteMediaType = '';

    public int $paletteLimit = self::PALETTE_PAGE;

    /** @var array<int, string> Picked palette entries ("media:12"), in the order they were clicked. */
    public array $picks = [];

    /** Which extra form the palette shows: '', 'url' or 'upload'. */
    public string $paletteForm = '';

    // URL-Formular (Legacy)
    public string $urlTitle = '';

    public string $urlAddress = '';

    public string $urlDuration = '';

    // Upload-Formular
    public string $uploadTitle = '';

    public string $uploadType = 'jingle';

    /** @var TemporaryUploadedFile|null */
    public $uploadFile = null;

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

    /* ------------------------------------------------------------------ Palette */

    public function switchTab(string $tab): void
    {
        $this->paletteTab = $tab;
        $this->paletteLimit = self::PALETTE_PAGE;
        $this->paletteForm = '';
    }

    public function updatedPaletteSearch(): void
    {
        $this->paletteLimit = self::PALETTE_PAGE;
    }

    public function updatedPaletteMediaType(): void
    {
        $this->paletteLimit = self::PALETTE_PAGE;
    }

    public function loadMore(): void
    {
        $this->paletteLimit += self::PALETTE_PAGE;
    }

    /**
     * Picks a palette entry for the next insert, or drops it again. The click order is
     * kept, so the operator decides in which order a block of elements lands.
     */
    public function togglePick(string $key): void
    {
        $this->picks = in_array($key, $this->picks, true)
            ? array_values(array_diff($this->picks, [$key]))
            : [...$this->picks, $key];
    }

    public function clearPicks(): void
    {
        $this->reset('picks');
    }

    /** Appends everything that is picked, in pick order. */
    public function insertPicks(): void
    {
        $added = $this->insertEntries($this->picks, $this->nextPosition());

        $this->reset('picks');
        $this->announceAdded($added);
    }

    /** Appends a single entry, for the one-click rows of the palette. */
    public function insertEntry(string $key): void
    {
        $this->announceAdded($this->insertEntries([$key], $this->nextPosition()));
    }

    /** Drops an entry into the list at a given position, for drag and drop out of the palette. */
    public function insertEntryAt(string $key, int $position): void
    {
        $position = max(0, $position);

        $this->resequence();
        $this->playlist->items()->where('position', '>=', $position)->increment('position');

        $added = $this->insertEntries([$key], $position);
        $this->resequence();

        $this->announceAdded($added);
    }

    public function submitUrl(): void
    {
        $element = ElementTypes::for('url');
        $this->validate($element->rules());

        $element->create($this->playlist, new ElementDraft(
            type: 'url',
            title: $this->urlTitle,
            url: $this->urlAddress,
            durationSeconds: $this->urlDuration !== '' ? (int) $this->urlDuration : null,
        ), $this->nextPosition());

        $this->reset('urlTitle', 'urlAddress', 'urlDuration', 'paletteForm');
        $this->announceAdded(1);
    }

    public function submitUpload(): void
    {
        $this->validate(['uploadType' => 'required|in:music,jingle']);

        $element = ElementTypes::for($this->uploadType, 'upload');
        $this->validate($element->rules());

        $element->create($this->playlist, new ElementDraft(
            type: $this->uploadType,
            title: $this->uploadTitle,
            upload: $this->uploadFile,
        ), $this->nextPosition());

        $this->reset('uploadTitle', 'uploadFile', 'paletteForm');
        $this->announceAdded(1);
    }

    /**
     * @param  array<int, string>  $keys  palette keys like "media:12"
     * @return int how many items were created
     */
    private function insertEntries(array $keys, int $position): int
    {
        $added = 0;

        foreach ($keys as $key) {
            [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');

            $element = ElementTypes::forPaletteEntry($kind, $id);
            $added += $element->create($this->playlist, $this->draftFor($kind, $id), $position + $added);
        }

        return $added;
    }

    private function draftFor(string $kind, string $id): ElementDraft
    {
        return match ($kind) {
            PlaylistPalette::TAB_MEDIA => new ElementDraft(type: 'media', mediaFileId: (int) $id),
            PlaylistPalette::TAB_CONTAINER => new ElementDraft(type: 'container', containerId: (int) $id),
            PlaylistPalette::TAB_EXTERNAL => new ElementDraft(type: 'external', externalSourceIds: [(int) $id]),
            default => new ElementDraft(type: $id),
        };
    }

    private function nextPosition(): int
    {
        return ($this->playlist->items()->max('position') ?? -1) + 1;
    }

    private function announceAdded(int $added): void
    {
        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element hinzugefügt.|[2,*]:count Elemente hinzugefügt.',
            $added,
            ['count' => $added],
        ));
    }

    /* -------------------------------------------------------------- Item-Aktionen */

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
        $this->announceDuplicated($this->copyItems($this->playlist->items()->where('id', $itemId)->get()));
    }

    public function duplicateSelected(): void
    {
        $copies = $this->copyItems($this->selectedItems());

        $this->reset('selectedItemIds');
        $this->announceDuplicated($copies);
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

    private function announceDuplicated(int $copies): void
    {
        $this->dispatch('notify', type: 'success', message: trans_choice(
            '{1}Element duplicated.|[2,*]:count elements duplicated.',
            $copies,
            ['count' => $copies],
        ));
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
        return PlaylistRuntime::format($seconds);
    }

    private function resequence(): void
    {
        $this->playlist->items()->orderBy('position')->get()
            ->each(function (PlaylistItem $item, int $index) {
                $item->update(['position' => $index]);
            });
    }

    public function render(PlaylistPalette $palette)
    {
        $items = $this->playlist->items()
            ->with([
                'mediaFile',
                'externalSource',
                'containerPlaylist.items.mediaFile',
                'containerPlaylist.items.externalSource',
            ])
            ->orderBy('position')
            ->get();

        // One more than the page, so the view knows whether to offer "load more".
        $entries = $palette->entries(
            $this->playlist->station,
            $this->paletteTab,
            $this->paletteSearch,
            $this->paletteLimit + 1,
            $this->paletteMediaType,
        );

        return view('livewire.playlist.manager', [
            'items' => $items,
            'runtime' => PlaylistRuntime::for($items),
            'paletteEntries' => $entries->take($this->paletteLimit),
            'hasMoreEntries' => $entries->count() > $this->paletteLimit,
            'stationTags' => $this->playlist->station->tags()->orderBy('name')->get(),
        ])->layout('layouts.app');
    }
}
