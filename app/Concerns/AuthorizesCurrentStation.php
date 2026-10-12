<?php

namespace App\Concerns;

use App\Models\Station;

/**
 * For Livewire components bound to the current station in `$station`. The page
 * ability is checked on mount and again on every request, so a removed member or a
 * lowered role cannot keep acting through an open tab.
 */
trait AuthorizesCurrentStation
{
    /** StationPolicy ability the whole component requires. */
    protected function stationAbility(): string
    {
        return 'view';
    }

    protected function authorizedCurrentStation(): Station
    {
        $station = auth()->user()->currentStation() ?? abort(403, __('No station selected.'));

        $this->authorize($this->stationAbility(), $station);

        return $station;
    }

    public function hydrateAuthorizesCurrentStation(): void
    {
        $this->authorize($this->stationAbility(), $this->station);
    }
}
