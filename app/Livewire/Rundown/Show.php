<?php

namespace App\Livewire\Rundown;

use App\Models\GeneratedPlaylist;
use App\Models\GeneratedPlaylistItem;
use App\Models\LiquidsoapState;
use App\Models\Station;
use App\Services\RundownGeneratorService;
use Carbon\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Rundown')]
class Show extends Component
{
    #[Locked]
    public Station $station;

    public string $date = '';

    public int $hour = 0;

    public ?GeneratedPlaylist $rundown = null;

    // Track tauschen
    public ?int $replacingItemId = null;

    public string $librarySearch = '';

    public ?int $selectedMediaFileId = null;

    public function mount(string $date, int $hour): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');

        $this->date = $date;
        $this->hour = $hour;
        $this->loadRundown();
    }

    private function loadRundown(): void
    {
        $this->rundown = GeneratedPlaylist::where('station_id', $this->station->id)
            ->whereDate('broadcast_date', $this->date)
            ->where('broadcast_hour', $this->hour)
            ->with('items.mediaFile')
            ->first();
    }

    public function regenerate(): void
    {
        if ($this->rundown?->isPlayed()) {
            $this->dispatch('notify', message: __('Dieser Rundown wurde bereits gespielt.'), type: 'error');

            return;
        }

        $broadcastDate = Carbon::parse($this->date);
        $weekday = $broadcastDate->dayOfWeekIso - 1; // 0=Mo ... 6=So

        $slot = $this->station->hourGridSlots()
            ->where('weekday', $weekday)
            ->where('hour', $this->hour)
            ->first();

        if (! $slot) {
            $this->dispatch('notify', message: __('Kein Slot im Wochenraster für diese Stunde.'), type: 'error');

            return;
        }

        try {
            app(RundownGeneratorService::class)->generate($this->station, $slot, $broadcastDate, true);
            $this->loadRundown();
            $this->dispatch('notify', message: __('Rundown generiert.'), type: 'success');
        } catch (\RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage(), type: 'error');
        }
    }

    /**
     * Gibt die Position zurück ab der Items gesperrt sind (bereits gespielt oder aktiv).
     * -1 bedeutet: kein aktiver State → alles editierbar.
     */
    private function lockedBelowPosition(): int
    {
        if (! $this->rundown) {
            return -1;
        }

        $state = LiquidsoapState::where('station_id', $this->station->id)->first();

        if (! $state || $state->current_rundown_id !== $this->rundown->id) {
            return -1;
        }

        // current_item_position ist der nächste zu pullende Track,
        // alles davor ist bereits gepullt (heruntergeladen) und nicht mehr änderbar.
        return $state->current_item_position;
    }

    /**
     * Position des tatsächlich laufenden Tracks (aus now_playing), oder -1.
     * Im Gegensatz zum Pull-Cursor zeigt das den echten Airplay-Stand.
     */
    private function playingPosition(): int
    {
        if (! $this->rundown) {
            return -1;
        }

        $state = LiquidsoapState::where('station_id', $this->station->id)
            ->with('nowPlayingItem')
            ->first();

        $nowPlaying = $state?->nowPlayingItem;

        // Am now_playing-Item festmachen, NICHT am Pull-Cursor (current_rundown_id):
        // der Cursor kann durch prefetch schon im nächsten Rundown stehen, während
        // hörbar noch ein Track aus diesem läuft.
        if (! $nowPlaying || $nowPlaying->generated_playlist_id !== $this->rundown->id) {
            return -1;
        }

        return $nowPlaying->position;
    }

    public function removeItem(int $itemId): void
    {
        abort_unless($this->rundown && ! $this->rundown->isPlayed(), 403);

        $item = $this->rundown->items()->findOrFail($itemId);

        if ($item->position < $this->lockedBelowPosition()) {
            $this->dispatch('notify', message: __('Dieser Track wird bereits gespielt und kann nicht entfernt werden.'), type: 'error');

            return;
        }

        $item->delete();
        $this->resequence();
        $this->loadRundown();
    }

    public function startReplacing(int $itemId): void
    {
        $item = $this->rundown?->items()->find($itemId);

        if ($item && $item->position < $this->lockedBelowPosition()) {
            $this->dispatch('notify', message: __('Dieser Track wird bereits gespielt und kann nicht ersetzt werden.'), type: 'error');

            return;
        }

        $this->replacingItemId = $itemId;
        $this->librarySearch = '';
        $this->selectedMediaFileId = null;
    }

    public function replaceItem(): void
    {
        abort_unless($this->rundown && ! $this->rundown->isPlayed(), 403);

        $item = $this->rundown->items()->findOrFail($this->replacingItemId);

        if ($item->position < $this->lockedBelowPosition()) {
            $this->dispatch('notify', message: __('Dieser Track wird bereits gespielt.'), type: 'error');
            $this->replacingItemId = null;

            return;
        }

        $this->validate(['selectedMediaFileId' => 'required|integer']);

        $mediaFile = $this->station->mediaFiles()->findOrFail($this->selectedMediaFileId);

        $item->update([
            'media_file_id' => $mediaFile->id,
            'title' => $mediaFile->title,
            'duration_seconds' => $mediaFile->duration_seconds,
            'source_type' => 'manual',
        ]);

        $this->replacingItemId = null;
        $this->selectedMediaFileId = null;
        $this->loadRundown();
        $this->dispatch('notify', message: __('Track ersetzt.'), type: 'success');
    }

    public function cancelReplacing(): void
    {
        $this->replacingItemId = null;
        $this->selectedMediaFileId = null;
    }

    private function resequence(): void
    {
        $this->rundown->items()->orderBy('position')->get()
            ->each(function (GeneratedPlaylistItem $item, int $index) {
                $item->update(['position' => $index]);
            });
    }

    public function render()
    {
        $libraryFiles = collect();

        if ($this->replacingItemId !== null) {
            $query = $this->station->mediaFiles();

            if ($this->librarySearch) {
                $query->where('title', 'like', '%'.$this->librarySearch.'%');
            }

            $libraryFiles = $query->orderBy('title')->limit(50)->get();
        }

        return view('livewire.rundown.show', [
            'libraryFiles' => $libraryFiles,
            'broadcastDate' => Carbon::parse($this->date),
            'lockedBelowPosition' => $this->lockedBelowPosition(),
            'playingPosition' => $this->playingPosition(),
        ])->layout('layouts.app');
    }
}
