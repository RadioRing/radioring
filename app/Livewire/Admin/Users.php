<?php

namespace App\Livewire\Admin;

use App\Enums\AppMode;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Nutzerverwaltung')]
class Users extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Banning accounts only exists in the cloud mode; a standalone operator knows every
     * user personally and revokes access by removing them from the station.
     */
    public function toggleBan(int $userId): void
    {
        abort_unless(AppMode::isMultiTenant(), 403);

        $user = $this->findManageable($userId);

        $user->banned_at = $user->isBanned() ? null : now();
        $user->save();

        $this->dispatch('notify',
            message: $user->isBanned() ? __('Nutzer gesperrt.') : __('Sperre aufgehoben.'),
            type: 'success',
        );
    }

    public function toggleAdmin(int $userId): void
    {
        $user = $this->findManageable($userId);

        $user->is_admin = ! $user->isAdmin();
        $user->save();

        $this->dispatch('notify',
            message: $user->isAdmin() ? __('Admin-Rechte erteilt.') : __('Admin-Rechte entzogen.'),
            type: 'success',
        );
    }

    /**
     * Lädt einen Nutzer und stellt sicher, dass es nicht der eingeloggte Admin selbst ist.
     */
    private function findManageable(int $userId): User
    {
        abort_if($userId === auth()->id(), 403, 'Eigenes Konto kann hier nicht verändert werden.');

        return User::findOrFail($userId);
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search !== '', fn (Builder $q) => $q
                ->where(fn (Builder $sub) => $sub
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->withCount('stations')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.users', [
            'users' => $users,
            'multiTenant' => AppMode::isMultiTenant(),
        ])->layout('layouts.app');
    }
}
