<?php

namespace App\Livewire\Station;

use App\Enums\StereoToolPreset;
use App\Models\Station;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Station bearbeiten')]
class Edit extends Component
{
    #[Locked]
    public Station $station;

    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|in:active,paused')]
    public string $status = 'active';

    public bool $regenerateRundownsNightly = false;

    public string $stereoToolLicenseKey = '';

    public string $stereoToolPreset = '';

    public string $memberEmail = '';

    public string $memberRole = 'editor';

    public function mount(Station $station): void
    {
        abort_unless($station->canBeManagedBy(auth()->user()), 403);

        $this->station = $station;
        $this->name = $station->name;
        $this->status = $station->status;
        $this->regenerateRundownsNightly = (bool) $station->regenerate_rundowns_nightly;
        $this->stereoToolLicenseKey = (string) $station->stereo_tool_license_key;
        $this->stereoToolPreset = (string) $station->stereo_tool_preset;
    }

    public function save(): void
    {
        $presetValues = array_map(fn (StereoToolPreset $p) => $p->value, StereoToolPreset::cases());

        $this->validate([
            'name' => 'required|string|min:2|max:80',
            'status' => 'required|in:active,paused',
            'regenerateRundownsNightly' => 'boolean',
            'stereoToolLicenseKey' => 'nullable|string|max:255',
            'stereoToolPreset' => ['nullable', Rule::in($presetValues)],
        ]);

        $attributes = [
            'name' => $this->name,
            'status' => $this->status,
            'regenerate_rundowns_nightly' => $this->regenerateRundownsNightly,
        ];

        // Stereo-Tool-Konfiguration nur, wenn ein Admin die Station freigeschaltet hat.
        // Die Freischaltung selbst (stereo_tool_enabled) bleibt dem Admin vorbehalten.
        if ($this->station->stereo_tool_enabled) {
            $attributes['stereo_tool_license_key'] = $this->stereoToolLicenseKey ?: null;
            $attributes['stereo_tool_preset'] = $this->stereoToolPreset ?: null;
        }

        $this->station->update($attributes);

        $this->dispatch('notify', message: __('Station gespeichert.'), type: 'success');
    }

    public function addMember(): void
    {
        $this->validate([
            'memberEmail' => 'required|email',
            'memberRole' => 'required|in:editor',
        ]);

        $user = User::where('email', $this->memberEmail)->first();

        if (! $user) {
            $this->addError('memberEmail', __('Es gibt keinen Nutzer mit dieser E-Mail-Adresse.'));

            return;
        }

        if ($this->station->isOwnedBy($user)) {
            $this->addError('memberEmail', __('Diese Person ist bereits Besitzer der Station.'));

            return;
        }

        if ($this->station->members()->where('users.id', $user->id)->exists()) {
            $this->addError('memberEmail', __('Diese Person hat bereits Zugriff.'));

            return;
        }

        $this->station->members()->attach($user->id, ['role' => $this->memberRole]);

        $this->reset('memberEmail');
        $this->dispatch('notify', message: __('Zugriff erteilt.'), type: 'success');
    }

    public function removeMember(int $userId): void
    {
        if ($userId === $this->station->user_id) {
            return;
        }

        $this->station->members()->detach($userId);

        $this->dispatch('notify', message: __('Zugriff entzogen.'), type: 'success');
    }

    public function delete(): void
    {
        $user = auth()->user();

        if ($user->currentStation()?->id === $this->station->id) {
            session()->forget('current_station_id');
        }

        $this->station->delete();

        $this->redirect(route('station.select'), navigate: true);
    }

    public function render()
    {
        return view('livewire.station.edit', [
            'members' => $this->station->members()->orderByPivot('role')->get(),
            'stereoToolPresets' => StereoToolPreset::options(),
        ])->layout('layouts.app');
    }
}
