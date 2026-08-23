<?php

namespace App\Livewire\Playlist;

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

    public function create(): void
    {
        $this->validate([
            'newName' => 'required|string|min:2|max:80',
            'newPlaybackMode' => 'required|in:sequential,random',
            'newStartMode' => 'required|in:soft,hard',
        ]);

        $this->station->playlists()->create([
            'name' => $this->newName,
            'playback_mode' => $this->newPlaybackMode,
            'start_mode' => $this->newStartMode,
        ]);

        $this->reset('newName', 'newPlaybackMode', 'newStartMode', 'showCreateForm');
        $this->dispatch('notify', message: __('Playlist erstellt.'), type: 'success');
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
        $playlist->delete();

        $this->dispatch('notify', message: __('Playlist gelöscht.'), type: 'success');
    }

    public function render()
    {
        return view('livewire.playlist.index', [
            'playlists' => $this->station->playlists()->withCount('items')->latest()->get(),
        ])->layout('layouts.app');
    }
}
