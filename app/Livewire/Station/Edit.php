<?php

namespace App\Livewire\Station;

use App\Models\Station;
use App\Models\User;
use App\Services\LiquidsoapCommandService;
use App\Support\StereoToolPresetLibrary;
use App\Support\StereoToolTerms;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Station bearbeiten')]
class Edit extends Component
{
    use WithFileUploads;

    #[Locked]
    public Station $station;

    #[Validate('required|string|min:2|max:80')]
    public string $name = '';

    #[Validate('required|in:active,paused')]
    public string $status = 'active';

    public bool $regenerateRundownsNightly = false;

    public string $stereoToolLicenseKey = '';

    public string $stereoToolPreset = '';

    public ?TemporaryUploadedFile $presetUpload = null;

    public string $presetName = '';

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
        $this->validate([
            'name' => 'required|string|min:2|max:80',
            'status' => 'required|in:active,paused',
            'regenerateRundownsNightly' => 'boolean',
            'stereoToolLicenseKey' => 'nullable|string|max:255',
            'stereoToolPreset' => [
                'nullable',
                Rule::in(StereoToolPresetLibrary::selectableIdentifiers($this->station)),
            ],
        ]);

        $attributes = [
            'name' => $this->name,
            'status' => $this->status,
            'regenerate_rundowns_nightly' => $this->regenerateRundownsNightly,
        ];

        // Stereo Tool configuration only once an admin has enabled the station. Enabling
        // it (stereo_tool_enabled) stays an admin decision and is never set here.
        if ($this->station->stereo_tool_enabled) {
            $attributes['stereo_tool_license_key'] = $this->stereoToolLicenseKey ?: null;
            $attributes['stereo_tool_preset'] = $this->stereoToolPreset ?: null;
        }

        $processingChanged = $this->station->stereo_tool_enabled && (
            ($attributes['stereo_tool_license_key'] ?? null) !== $this->station->stereo_tool_license_key
            || ($attributes['stereo_tool_preset'] ?? null) !== $this->station->stereo_tool_preset
        );

        $this->station->update($attributes);

        $this->dispatch('notify', message: __('Station saved.'), type: 'success');

        if ($processingChanged) {
            $this->applyProcessing();
        }
    }

    /**
     * Uploads stay private to this station and are never redistributed, which is what
     * keeps a preset somebody found on a forum out of the repository.
     */
    public function uploadPreset(): void
    {
        abort_unless($this->station->stereo_tool_enabled, 403);

        $this->validate([
            'presetUpload' => 'required|file|max:2048',
            'presetName' => 'nullable|string|max:80',
        ]);

        $contents = $this->presetUpload->get();

        if (! StereoToolPresetLibrary::looksLikePreset($contents)) {
            $this->addError('presetUpload', __('That does not look like a Stereo Tool preset (.sts).'));

            return;
        }

        $path = $this->presetUpload->storeAs(
            $this->station->stereoToolPresetDirectory(),
            Str::uuid()->toString().'.sts',
            'local',
        );

        $this->station->stereoToolPresets()->create([
            'uploaded_by' => auth()->id(),
            'name' => $this->presetName
                ?: StereoToolPresetLibrary::nameIn($contents)
                ?: pathinfo((string) $this->presetUpload->getClientOriginalName(), PATHINFO_FILENAME),
            'path' => $path,
            'size' => strlen($contents),
        ]);

        $this->reset('presetUpload', 'presetName');

        $this->dispatch('notify', message: __('Preset uploaded.'), type: 'success');
    }

    public function deletePreset(int $presetId): void
    {
        abort_unless($this->station->stereo_tool_enabled, 403);

        $preset = $this->station->stereoToolPresets()->findOrFail($presetId);
        $wasSelected = $this->station->stereo_tool_preset === $preset->identifier();

        $preset->deleteWithFile();

        if ($wasSelected) {
            $this->station->update(['stereo_tool_preset' => null]);
            $this->stereoToolPreset = '';
            $this->applyProcessing();
        }

        $this->dispatch('notify', message: __('Preset deleted.'), type: 'success');
    }

    /**
     * Replaces only the player process, not the container: the shortest interruption that
     * can apply a processing change.
     */
    public function applyProcessing(): void
    {
        abort_unless($this->station->stereo_tool_enabled, 403);

        app(LiquidsoapCommandService::class)->restart($this->station);

        $this->dispatch('notify',
            message: __('Restarting the player so the processing change takes effect.'),
            type: 'success',
        );
    }

    public function addMember(): void
    {
        $this->validate([
            'memberEmail' => 'required|email',
            'memberRole' => 'required|in:editor,owner',
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

    /**
     * Promote an editor to owner or demote an owner back to editor.
     *
     * The founder's own row is untouchable: their role is what keeps the station
     * manageable if every promoted owner is later demoted or removed.
     */
    public function changeMemberRole(int $userId, string $role): void
    {
        abort_unless(in_array($role, ['owner', 'editor'], true), 422);

        if ($userId === $this->station->user_id) {
            return;
        }

        $this->station->members()->updateExistingPivot($userId, ['role' => $role]);

        $this->dispatch('notify', message: $role === 'owner'
            ? __('Member promoted to owner.')
            : __('Member set back to editor.'), type: 'success');
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

        abort_unless($this->station->canBeDeletedBy($user), 403);

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
            'stereoToolPresetGroups' => StereoToolPresetLibrary::grouped($this->station),
            'stereoToolUploads' => $this->station->stereoToolPresets()->orderBy('name')->get(),
            'stereoToolLicenceUrl' => StereoToolTerms::licenceUrl(),
        ])->layout('layouts.app');
    }
}
