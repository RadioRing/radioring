<?php

namespace App\Livewire\Playlist;

use App\Models\Playlist;
use App\Models\Station;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Playlisten')]
class Index extends Component
{
    #[Locked]
    public Station $station;

    public bool $showCreateForm = false;

    /** Which kind the create form builds: a playlist or a reusable container. */
    #[Validate('required|in:playlist,container')]
    public string $newKind = Playlist::KIND_PLAYLIST;

    #[Validate('required|string|min:2|max:80')]
    public string $newName = '';

    #[Validate('required|in:sequential,random')]
    public string $newPlaybackMode = 'sequential';

    #[Validate('required|in:soft,hard')]
    public string $newStartMode = 'soft';

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');
    }

    /** Opens the create form for a playlist or for a container. */
    public function startCreating(string $kind = Playlist::KIND_PLAYLIST): void
    {
        $this->newKind = in_array($kind, [Playlist::KIND_PLAYLIST, Playlist::KIND_CONTAINER], true)
            ? $kind
            : Playlist::KIND_PLAYLIST;
        $this->showCreateForm = true;
    }

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
            'newStartMode' => 'required|in:soft,hard',
        ]);

        // A container is never scheduled on its own, so playback and start mode of the
        // embedding playlist apply: keep the defaults out of the operator's way.
        $this->station->playlists()->create([
            'name' => $this->newName,
            'kind' => $this->newKind,
            'playback_mode' => $isContainer ? 'sequential' : $this->newPlaybackMode,
            'start_mode' => $isContainer ? 'soft' : $this->newStartMode,
        ]);

        $this->reset('newName', 'newPlaybackMode', 'newStartMode', 'newKind', 'showCreateForm');
        $this->dispatch('notify', message: $isContainer
            ? __('Container created.')
            : __('Playlist erstellt.'), type: 'success');
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

    public function render()
    {
        return view('livewire.playlist.index', [
            'playlists' => $this->station->playlists()->schedulable()->withCount('items')->latest()->get(),
            'containers' => $this->station->playlists()->containers()
                ->withCount(['items', 'embeddingItems'])->latest()->get(),
        ])->layout('layouts.app');
    }
}
