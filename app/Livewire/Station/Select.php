<?php

namespace App\Livewire\Station;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Radiostation auswählen')]
class Select extends Component
{
    public function choose(int $stationId): void
    {
        $station = auth()->user()->accessibleStations()->findOrFail($stationId);
        auth()->user()->setCurrentStation($station);

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.station.select', [
            'stations' => auth()->user()->accessibleStations()->latest('stations.created_at')->get(),
        ])->layout('layouts.app');
    }
}
