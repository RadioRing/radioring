<?php

namespace App\Livewire\Admin;

use App\Models\Station;
use App\Support\StereoToolTerms;
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
     * Enables or disables the CPU-intensive Stereo Tool processing for one station.
     * Purely administrative: the operator supplies licence key and preset afterwards in
     * the station settings.
     *
     * Enabling requires the Thimeo licence to be accepted for the whole instance first,
     * because that is what permits the bundled library to be used at all. Disabling is
     * always allowed, so a station can be switched off even if acceptance was withdrawn
     * in the meantime.
     */
    public function toggleStereoTool(int $stationId): void
    {
        $station = Station::findOrFail($stationId);

        if (! $station->stereo_tool_enabled && ! StereoToolTerms::accepted()) {
            $this->dispatch('notify',
                message: __('Accept the Stereo Tool licence in the instance settings first.'),
                type: 'error',
            );

            return;
        }

        $station->stereo_tool_enabled = ! $station->stereo_tool_enabled;
        $station->save();

        $this->dispatch('notify',
            message: $station->stereo_tool_enabled
                ? __('Stereo Tool enabled.')
                : __('Stereo Tool disabled.'),
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
            'stereoToolTermsAccepted' => StereoToolTerms::accepted(),
        ])->layout('layouts.app');
    }
}
