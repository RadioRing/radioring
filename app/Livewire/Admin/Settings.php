<?php

namespace App\Livewire\Admin;

use App\Enums\AppMode;
use App\Models\Tenant;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Instanz-Einstellungen')]
class Settings extends Component
{
    public string $mode = '';

    public function mount(): void
    {
        $this->mode = AppMode::current()->value;
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
        ])->layout('layouts.app');
    }
}
