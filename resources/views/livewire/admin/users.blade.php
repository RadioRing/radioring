<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-people me-2 text-primary"></i>{{ __('Nutzerverwaltung') }}
        </h4>
        <a href="{{ route('admin.invite-codes') }}" class="btn btn-outline-secondary btn-sm" wire:navigate>
            <i class="bi bi-ticket-perforated me-1"></i>{{ __('Einladungscodes') }}
        </a>
    </div>

    <div class="mb-3" style="max-width:360px">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" wire:model.live.debounce.300ms="search"
                   class="form-control" placeholder="{{ __('Name oder E-Mail ...') }}">
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('E-Mail') }}</th>
                        <th class="text-center">{{ __('Stationen') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end">{{ __('Aktionen') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr wire:key="user-{{ $user->id }}">
                            <td class="fw-medium">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <span class="badge text-bg-light border ms-1">{{ __('Du') }}</span>
                                @endif
                            </td>
                            <td class="text-muted-sm">{{ $user->email }}</td>
                            <td class="text-center">{{ $user->stations_count }}</td>
                            <td>
                                @if($user->isAdmin())
                                    <span class="badge text-bg-primary">{{ __('Admin') }}</span>
                                @endif
                                @if($multiTenant && $user->isBanned())
                                    <span class="badge text-bg-danger">{{ __('Gesperrt') }}</span>
                                @elseif(! $user->isAdmin())
                                    <span class="badge text-bg-success-subtle text-success">{{ __('Aktiv') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($user->id !== auth()->id())
                                    <div class="btn-group btn-group-sm">
                                        @if($multiTenant && ! $user->isAdmin())
                                            <form method="POST" action="{{ route('admin.impersonate', $user) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-secondary"
                                                        title="{{ __('Als dieser Nutzer anmelden') }}">
                                                    <i class="bi bi-person-badge"></i>
                                                </button>
                                            </form>
                                        @endif

                                        <button class="btn btn-outline-secondary"
                                                @click="$dispatch('confirm-dialog', { message: @js($user->isAdmin()
                                                    ? __('Admin-Rechte von :name entziehen?', ['name' => $user->name])
                                                    : __(':name zum Admin machen?', ['name' => $user->name])), confirmClass: 'btn-primary', onConfirm: () => $wire.toggleAdmin({{ $user->id }}) })"
                                                title="{{ $user->isAdmin() ? __('Admin-Rechte entziehen') : __('Zum Admin machen') }}">
                                            <i class="bi {{ $user->isAdmin() ? 'bi-shield-minus' : 'bi-shield-plus' }}"></i>
                                        </button>

                                        @if($multiTenant)
                                        <button class="btn {{ $user->isBanned() ? 'btn-outline-success' : 'btn-outline-danger' }}"
                                                @click="$dispatch('confirm-dialog', { message: @js($user->isBanned()
                                                    ? __('Sperre für :name aufheben?', ['name' => $user->name])
                                                    : __(':name sperren? Aktive Sitzungen werden beendet.', ['name' => $user->name])), confirmClass: @js($user->isBanned() ? 'btn-success' : 'btn-danger'), onConfirm: () => $wire.toggleBan({{ $user->id }}) })"
                                                title="{{ $user->isBanned() ? __('Entsperren') : __('Sperren') }}">
                                            <i class="bi {{ $user->isBanned() ? 'bi-unlock' : 'bi-lock' }}"></i>
                                        </button>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted-sm">–</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">{{ __('Keine Nutzer gefunden.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $users->links() }}
    </div>
</div>
