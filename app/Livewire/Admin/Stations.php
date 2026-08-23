<?php

namespace App\Livewire\Admin;

use App\Models\Station;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Stationen')]
class Stations extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Schaltet das CPU-intensive Stereo-Tool-Processing für eine Station frei
     * bzw. wieder ab. Rein administrative Aktion – der Betreiber hinterlegt
     * Lizenz und Preset danach selbst in den Stationseinstellungen.
     */
    public function toggleStereoTool(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        $station->stereo_tool_enabled = ! $station->stereo_tool_enabled;
        $station->save();

        $this->dispatch('notify',
            message: $station->stereo_tool_enabled
                ? __('Stereo Tool freigeschaltet.')
                : __('Stereo Tool deaktiviert.'),
            type: 'success',
        );
    }

    public function render()
    {
        $stations = Station::query()
            ->when($this->search !== '', fn (Builder $q) => $q
                ->where(fn (Builder $sub) => $sub
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('slug', 'like', '%'.$this->search.'%')))
            ->with('owner')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.stations', [
            'stations' => $stations,
        ])->layout('layouts.app');
    }
}
