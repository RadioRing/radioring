<?php

namespace App\Livewire\Admin;

use App\Enums\AppMode;
use App\Models\Tenant;
use App\Support\StereoToolTerms;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Instanz-Einstellungen')]
class Settings extends Component
{
    public string $mode = '';

    /** Bound to the licence checkbox, not persisted until the operator confirms. */
    public bool $stereoToolTermsAgreed = false;

    public function mount(): void
    {
        $this->mode = AppMode::current()->value;
        $this->stereoToolTermsAgreed = StereoToolTerms::accepted();
    }

    #[Computed]
    public function tenantCount(): int
    {
        return Tenant::count();
    }

    /**
     * The tenant that new registrations would join once standalone is active.
     */
    #[Computed]
    public function standaloneTenantName(): ?string
    {
        return Tenant::query()->oldest('id')->value('name');
    }

    /**
     * Would switching to standalone change where new registrations land? Only relevant
     * once more than one tenant exists. The operator gets a warning first.
     */
    #[Computed]
    public function switchingToStandaloneIsAmbiguous(): bool
    {
        return AppMode::current()->isCloud() && $this->tenantCount() > 1;
    }

    #[Computed]
    public function stereoToolTermsAccepted(): bool
    {
        return StereoToolTerms::accepted();
    }

    #[Computed]
    public function stereoToolAcceptance(): ?string
    {
        $at = StereoToolTerms::acceptedAt();

        if (! $at) {
            return null;
        }

        return __('Accepted on :date by :name.', [
            'date' => $at->isoFormat('LLL'),
            'name' => StereoToolTerms::acceptedBy()?->name ?? __('a deleted account'),
        ]);
    }

    /**
     * Records instance-wide acceptance of the Thimeo licence. Until this happens, no
     * station can have Stereo Tool enabled (see Admin\Stations::toggleStereoTool).
     */
    public function acceptStereoToolTerms(): void
    {
        if (! $this->stereoToolTermsAgreed) {
            $this->addError('stereoToolTermsAgreed', __('Please confirm that you accept the Stereo Tool licence.'));

            return;
        }

        StereoToolTerms::accept(auth()->user());

        unset($this->stereoToolTermsAccepted, $this->stereoToolAcceptance);

        $this->dispatch('notify', message: __('Stereo Tool licence accepted.'), type: 'success');
    }

    /**
     * Withdraws acceptance. Stereo Tool is switched off on every station, because running
     * it on a licence the instance no longer accepts is exactly what acceptance prevents.
     */
    public function revokeStereoToolTerms(): void
    {
        StereoToolTerms::revoke();

        $this->stereoToolTermsAgreed = false;

        unset($this->stereoToolTermsAccepted, $this->stereoToolAcceptance);

        $this->dispatch('notify',
            message: __('Stereo Tool licence withdrawn and disabled on all stations.'),
            type: 'success',
        );
    }

    public function save(): void
    {
        $this->validate([
            'mode' => ['required', 'in:standalone,cloud'],
        ]);

        $target = AppMode::from($this->mode);

        if ($target === AppMode::current()) {
            return;
        }

        AppMode::switchTo($target);

        unset($this->tenantCount, $this->standaloneTenantName, $this->switchingToStandaloneIsAmbiguous);

        $this->dispatch('notify',
            message: __('Operating mode switched to :mode.', ['mode' => $target->label()]),
            type: 'success',
        );
    }

    public function render()
    {
        return view('livewire.admin.settings', [
            'modes' => AppMode::cases(),
            'currentMode' => AppMode::current(),
            'stereoToolLicenceUrl' => StereoToolTerms::licenceUrl(),
        ])->layout('layouts.app');
    }
}
