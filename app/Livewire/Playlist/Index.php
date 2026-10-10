<?php

namespace App\Livewire\Playlist;

use App\Concerns\AuthorizesCurrentStation;
use App\Models\Playlist;
use App\Models\Station;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Playlisten')]
class Index extends Component
{
    use AuthorizesCurrentStation;

    #[Locked]
    public Station $station;

    /**
     * Search, tab and sort live in the URL, so coming back from the editor lands on the
     * same narrowed list instead of the top of an unfiltered one.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** 'playlists' or 'containers'. */
    #[Url(except: 'playlists')]
    public string $tab = 'playlists';

    /** 'name' or 'recent'. */
    #[Url(except: 'name')]
    public string $sort = 'name';

    /** Row that was just created or duplicated, highlighted once. */
    public ?int $highlightId = null;

    public bool $showCreateForm = false;

    /** Which kind the create form builds: a playlist or a reusable container. */
    #[Validate('required|in:playlist,container')]
    public string $newKind = Playlist::KIND_PLAYLIST;

    #[Validate('required|string|min:2|max:80')]
    public string $newName = '';

    #[Validate('required|in:sequential,random')]
    public string $newPlaybackMode = 'sequential';

    protected function stationAbility(): string
    {
        return 'program';
    }

    public function mount(): void
    {
        $this->station = $this->authorizedCurrentStation();
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab === 'containers' ? 'containers' : 'playlists';
    }

    /** Opens the create dialog for a playlist or for a container. */
    public function startCreating(string $kind = Playlist::KIND_PLAYLIST): void
    {
        $this->resetValidation();
        $this->newKind = in_array($kind, [Playlist::KIND_PLAYLIST, Playlist::KIND_CONTAINER], true)
            ? $kind
            : Playlist::KIND_PLAYLIST;
        // A search that found nothing is the usual reason to create one, so offer it as name.
        $this->newName = $this->search;
        $this->showCreateForm = true;

        $this->dispatch('playlist-create-opened');
    }

    public function cancelCreating(): void
    {
        $this->reset('newName', 'newPlaybackMode', 'newKind', 'showCreateForm');
        $this->resetValidation();

        $this->dispatch('playlist-create-closed');
    }

    /**
     * Creates the playlist and opens it in the editor right away: an empty playlist is
     * only ever created to be filled.
     */
    public function create(): void
    {
        $isContainer = $this->newKind === Playlist::KIND_CONTAINER;

        $this->validate($isContainer ? [
            'newName' => 'required|string|min:2|max:80',
            'newKind' => 'required|in:playlist,container',
        ] : [
            'newName' => 'required|string|min:2|max:80',
            'newKind' => 'required|in:playlist,container',
            'newPlaybackMode' => 'required|in:sequential,random',
        ]);

        // Containers inherit the playback mode of the embedding playlist.
        $playlist = $this->station->playlists()->create([
            'name' => $this->newName,
            'kind' => $this->newKind,
            'playback_mode' => $isContainer ? 'sequential' : $this->newPlaybackMode,
        ]);

        $this->reset('newName', 'newPlaybackMode', 'newKind', 'showCreateForm');
        $this->dispatch('playlist-create-closed');
        $this->dispatch('notify', message: $isContainer
            ? __('Container created.')
            : __('Playlist erstellt.'), type: 'success');

        // A full page load, so the closing dialog leaves no backdrop behind.
        $this->redirectRoute('playlist.manager', $playlist);
    }

    /**
     * Dupliziert eine Playlist samt aller Elemente (für eine ähnliche neue Playlist).
     */
    public function duplicate(int $playlistId): void
    {
        $original = $this->station->playlists()->with('items')->findOrFail($playlistId);

        $copy = $original->replicate(['created_at', 'updated_at']);
        $copy->name = Str::limit($original->name, 72, '').' '.__('(Kopie)');
        $copy->save();

        foreach ($original->items as $item) {
            $newItem = $item->replicate();
            $newItem->playlist_id = $copy->id;
            $newItem->save();
        }

        $this->highlightId = $copy->id;
        $this->dispatch('playlist-row-highlighted', id: $copy->id);
        $this->dispatch('notify', message: __('Playlist dupliziert.'), type: 'success');
    }

    public function delete(int $playlistId): void
    {
        $playlist = $this->station->playlists()->findOrFail($playlistId);

        // Containers leave placeholder items behind in every playlist that embeds them;
        // drop those together with the container so no empty slot survives.
        if ($playlist->isContainer()) {
            $playlist->embeddingItems()->delete();
        }

        $playlist->delete();

        $this->dispatch('notify', message: $playlist->isContainer()
            ? __('Container deleted.')
            : __('Playlist gelöscht.'), type: 'success');
    }

    /**
     * Playlists or containers of this station, narrowed by the search and sorted.
     *
     * @param  Builder<Playlist>  $query
     * @return Collection<int, Playlist>
     */
    private function listed(Builder $query): Collection
    {
        if ($this->search !== '') {
            $query->where('name', 'like', '%'.$this->search.'%');
        }

        $this->sort === 'recent'
            ? $query->latest('updated_at')->latest('id')
            : $query->orderBy('name');

        return $query->get();
    }

    public function render()
    {
        $playlists = $this->listed($this->station->playlists()->schedulable()
            ->with('firstItem')->withCount(['items', 'hourGridSlots'])->getQuery());
        $containers = $this->listed($this->station->playlists()->containers()
            ->withCount(['items', 'embeddingItems'])->getQuery());

        return view('livewire.playlist.index', [
            'playlists' => $playlists,
            'containers' => $containers,
            'totalPlaylists' => $this->station->playlists()->schedulable()->count(),
            'totalContainers' => $this->station->playlists()->containers()->count(),
        ])->layout('layouts.app');
    }
}
