<?php

namespace App\Livewire\Protocol;

use App\Models\Station;
use App\Models\StationLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Protokoll')]
class Index extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    #[Locked]
    public Station $station;

    /** Tagesfilter (Y-m-d); leer = alle Tage. */
    public string $date = '';

    /**
     * Ereignisfilter: '' = alle, sonst eine der Kategorien aus applyFilter().
     */
    public string $filter = '';

    public string $search = '';

    public function mount(): void
    {
        $this->station = auth()->user()->currentStation()
            ?? abort(403, 'Keine Station ausgewählt.');

        $this->date = today()->toDateString();
    }

    public function updatedDate(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('filter', 'search');
        $this->date = today()->toDateString();
        $this->resetPage();
    }

    /**
     * Übersetzt die gewählte Filter-Kategorie in Query-Bedingungen.
     */
    private function applyFilter(Builder $query): void
    {
        match ($this->filter) {
            'playlist' => $query->where('event', StationLog::EVENT_TRACK)->where('source', 'playlist'),
            'live_track' => $query->where('event', StationLog::EVENT_TRACK)->where('source', 'live'),
            'live_switch' => $query->whereIn('event', [StationLog::EVENT_LIVE_STARTED, StationLog::EVENT_LIVE_STOPPED]),
            'rundown' => $query->where('event', StationLog::EVENT_RUNDOWN_GENERATED),
            default => null,
        };
    }

    public function render()
    {
        $entries = StationLog::query()
            ->where('station_id', $this->station->id)
            ->when($this->date !== '', fn (Builder $q) => $q->whereDate('occurred_at', $this->date))
            ->when($this->filter !== '', fn (Builder $q) => $this->applyFilter($q))
            ->when($this->search !== '', fn (Builder $q) => $q
                ->where(fn (Builder $sub) => $sub
                    ->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('artist', 'like', '%'.$this->search.'%')))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(50);

        return view('livewire.protocol.index', [
            'entries' => $entries,
        ])->layout('layouts.app');
    }
}
