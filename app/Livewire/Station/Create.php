<?php

namespace App\Livewire\Station;

use App\Enums\AppMode;
use App\Models\Station;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Radiostation erstellen')]
class Create extends Component
{
    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    public function save(): void
    {
        $user = auth()->user();

        if (! $user->canCreateStation()) {
            $this->addError('name', __('Du hast dein Stationslimit (:quota) erreicht.', ['quota' => $user->tenant?->station_quota]));

            return;
        }

        $this->validate();

        $slug = $this->uniqueSlug(Str::slug($this->name));

        $station = $user->stations()->create([
            'name' => $this->name,
            'slug' => $slug,
            'status' => 'active',
        ]);

        $user->setCurrentStation($station);

        $this->redirect(route('dashboard'), navigate: true);
    }

    /**
     * Switches to a station the user already has access to, so someone invited as owner
     * or editor is never forced to create a station of their own.
     */
    public function choose(int $stationId): void
    {
        $station = auth()->user()->accessibleStations()->findOrFail($stationId);
        auth()->user()->setCurrentStation($station);

        $this->redirect(route('dashboard'), navigate: true);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;

        while (Station::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function render()
    {
        return view('livewire.station.create', [
            'canCreate' => auth()->user()->canCreateStation(),
            'quota' => auth()->user()->tenant?->station_quota,
            'showQuota' => AppMode::isMultiTenant(),
            'used' => auth()->user()->stations()->count(),
            'accessibleStations' => auth()->user()->accessibleStations()->orderBy('stations.name')->get(),
        ])->layout('layouts.app');
    }
}
