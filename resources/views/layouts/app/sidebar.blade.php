<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body>
        <div class="d-flex">

            {{-- ── Desktop Sidebar ─────────────────────────────────── --}}
            <nav id="sidebar" class="bg-dark text-white d-none d-md-flex p-3">
                {{-- Logo --}}
                <div class="mb-4 px-2">
                    <a href="{{ route('dashboard') }}" class="text-decoration-none text-white fw-semibold fs-5" wire:navigate>
                        <i class="bi bi-broadcast-pin me-1 text-primary"></i>
                        {{ config('app.name', 'RadioRing') }}
                    </a>
                </div>

                {{-- Navigation --}}
                <ul class="nav flex-column flex-grow-1">
                    <li class="nav-item">
                        <a href="{{ route('dashboard') }}"
                           class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-speedometer2 me-2"></i>{{ __('Dashboard') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('playlist.index') }}"
                           class="nav-link {{ request()->routeIs('playlist.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-music-note-list me-2"></i>{{ __('Playlisten') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('media.index') }}"
                           class="nav-link {{ request()->routeIs('media.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-collection-play me-2"></i>{{ __('Medienbibliothek') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('external-source.index') }}"
                           class="nav-link {{ request()->routeIs('external-source.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-rss me-2"></i>{{ __('Externe Quellen') }}
                        </a>
                    </li>

                    <li class="nav-item mt-2">
                        <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('PROGRAMMPLANUNG') }}</span>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('hour-grid.index') }}"
                           class="nav-link {{ request()->routeIs('hour-grid.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-grid-3x3-gap me-2"></i>{{ __('Wochenraster') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('rundown.show', [today()->toDateString(), now()->hour]) }}"
                           class="nav-link {{ request()->routeIs('rundown.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-clock-history me-2"></i>{{ __('Rundown') }}
                        </a>
                    </li>

                    <li class="nav-item mt-2">
                        <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('STREAMING') }}</span>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('output.index') }}"
                           class="nav-link {{ request()->routeIs('output.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-broadcast me-2"></i>{{ __('Ausgänge') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('protocol.index') }}"
                           class="nav-link {{ request()->routeIs('protocol.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-card-list me-2"></i>{{ __('Protokoll') }}
                        </a>
                    </li>

                    @if(auth()->user()->isAdmin())
                        <li class="nav-item mt-2">
                            <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('ADMINISTRATION') }}</span>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.users') }}"
                               class="nav-link {{ request()->routeIs('admin.users') ? 'active' : '' }}"
                               wire:navigate>
                                <i class="bi bi-people me-2"></i>{{ __('Nutzer') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.stations') }}"
                               class="nav-link {{ request()->routeIs('admin.stations') ? 'active' : '' }}"
                               wire:navigate>
                                <i class="bi bi-broadcast-pin me-2"></i>{{ __('Stationen') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.invite-codes') }}"
                               class="nav-link {{ request()->routeIs('admin.invite-codes') ? 'active' : '' }}"
                               wire:navigate>
                                <i class="bi bi-ticket-perforated me-2"></i>{{ __('Einladungscodes') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.settings') }}"
                               class="nav-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}"
                               wire:navigate>
                                <i class="bi bi-sliders me-2"></i>{{ __('Instance settings') }}
                            </a>
                        </li>
                    @endif

                    <li class="nav-item mt-auto">
                        <a href="{{ route('help.index') }}"
                           class="nav-link {{ request()->routeIs('help.*') ? 'active' : '' }}"
                           wire:navigate>
                            <i class="bi bi-question-circle me-2"></i>{{ __('Hilfe') }}
                        </a>
                    </li>
                </ul>

                {{-- User menu --}}
                <div class="mt-auto pt-3 border-top border-secondary">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center gap-2 text-white text-decoration-none dropdown-toggle"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="d-flex align-items-center justify-content-center bg-secondary rounded-circle fw-bold"
                                  style="width:32px;height:32px;font-size:.75rem;">
                                {{ auth()->user()->initials() }}
                            </span>
                            <div class="lh-sm overflow-hidden">
                                <div class="text-truncate fw-medium" style="max-width:140px;">{{ auth()->user()->name }}</div>
                                <div class="text-truncate text-muted-sm" style="max-width:140px;">{{ auth()->user()->email }}</div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark mb-2">
                            <li>
                                <a class="dropdown-item" href="{{ route('profile.edit') }}" wire:navigate>
                                    <i class="bi bi-gear me-2"></i>{{ __('Settings') }}
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item" data-test="logout-button">
                                        <i class="bi bi-box-arrow-right me-2"></i>{{ __('Log out') }}
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>

                    <x-app-version />
                </div>
            </nav>

            {{-- ── Content ──────────────────────────────────────────── --}}
            <div id="content-wrapper" class="flex-grow-1 bg-light">

                {{-- Mobile top navbar --}}
                <nav class="navbar navbar-dark bg-dark d-md-none px-3">
                    <button class="navbar-toggler" type="button"
                            data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar"
                            aria-controls="mobileSidebar">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <a class="navbar-brand fw-semibold" href="{{ route('dashboard') }}" wire:navigate>
                        <i class="bi bi-broadcast-pin me-1 text-primary"></i>
                        {{ config('app.name', 'RadioRing') }}
                    </a>
                </nav>

                @if(session()->has('impersonator_id'))
                    <div class="alert alert-warning d-flex align-items-center justify-content-between rounded-0 mb-0 py-2 px-4">
                        <span>
                            <i class="bi bi-person-badge me-2"></i>
                            {{ __('Du bist als :name (:email) angemeldet.', ['name' => auth()->user()->name, 'email' => auth()->user()->email]) }}
                        </span>
                        <form method="POST" action="{{ route('admin.impersonate.leave') }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-dark">
                                <i class="bi bi-box-arrow-left me-1"></i>{{ __('Zurück zum Admin') }}
                            </button>
                        </form>
                    </div>
                @endif

                <main class="p-4">
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- ── Mobile Offcanvas Sidebar ────────────────────────────── --}}
        <div class="offcanvas offcanvas-start bg-dark text-white" tabindex="-1" id="mobileSidebar">
            <div class="offcanvas-header border-bottom border-secondary">
                <h5 class="offcanvas-title fw-semibold">
                    <i class="bi bi-broadcast-pin me-1 text-primary"></i>
                    {{ config('app.name', 'RadioRing') }}
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column">
                <ul class="nav flex-column flex-grow-1">
                    <li class="nav-item">
                        <a href="{{ route('dashboard') }}"
                           class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-speedometer2 me-2"></i>{{ __('Dashboard') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('playlist.index') }}"
                           class="nav-link {{ request()->routeIs('playlist.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-music-note-list me-2"></i>{{ __('Playlisten') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('media.index') }}"
                           class="nav-link {{ request()->routeIs('media.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-collection-play me-2"></i>{{ __('Medienbibliothek') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('external-source.index') }}"
                           class="nav-link {{ request()->routeIs('external-source.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-rss me-2"></i>{{ __('Externe Quellen') }}
                        </a>
                    </li>
                    <li class="nav-item mt-2">
                        <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('PROGRAMMPLANUNG') }}</span>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('hour-grid.index') }}"
                           class="nav-link {{ request()->routeIs('hour-grid.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-grid-3x3-gap me-2"></i>{{ __('Wochenraster') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('rundown.show', [today()->toDateString(), now()->hour]) }}"
                           class="nav-link {{ request()->routeIs('rundown.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-clock-history me-2"></i>{{ __('Rundown') }}
                        </a>
                    </li>
                    <li class="nav-item mt-2">
                        <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('STREAMING') }}</span>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('output.index') }}"
                           class="nav-link {{ request()->routeIs('output.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-broadcast me-2"></i>{{ __('Ausgänge') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('protocol.index') }}"
                           class="nav-link {{ request()->routeIs('protocol.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-card-list me-2"></i>{{ __('Protokoll') }}
                        </a>
                    </li>

                    @if(auth()->user()->isAdmin())
                        <li class="nav-item mt-2">
                            <span class="nav-link text-muted disabled" style="font-size:.7rem;letter-spacing:.05em">{{ __('ADMINISTRATION') }}</span>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.users') }}"
                               class="nav-link {{ request()->routeIs('admin.users') ? 'active' : '' }}"
                               wire:navigate data-bs-dismiss="offcanvas">
                                <i class="bi bi-people me-2"></i>{{ __('Nutzer') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.stations') }}"
                               class="nav-link {{ request()->routeIs('admin.stations') ? 'active' : '' }}"
                               wire:navigate data-bs-dismiss="offcanvas">
                                <i class="bi bi-broadcast-pin me-2"></i>{{ __('Stationen') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.invite-codes') }}"
                               class="nav-link {{ request()->routeIs('admin.invite-codes') ? 'active' : '' }}"
                               wire:navigate data-bs-dismiss="offcanvas">
                                <i class="bi bi-ticket-perforated me-2"></i>{{ __('Einladungscodes') }}
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('admin.settings') }}"
                               class="nav-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}"
                               wire:navigate data-bs-dismiss="offcanvas">
                                <i class="bi bi-sliders me-2"></i>{{ __('Instance settings') }}
                            </a>
                        </li>
                    @endif

                    <li class="nav-item mt-auto">
                        <a href="{{ route('help.index') }}"
                           class="nav-link {{ request()->routeIs('help.*') ? 'active' : '' }}"
                           wire:navigate data-bs-dismiss="offcanvas">
                            <i class="bi bi-question-circle me-2"></i>{{ __('Hilfe') }}
                        </a>
                    </li>
                </ul>
                <div class="pt-3 border-top border-secondary">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="d-flex align-items-center justify-content-center bg-secondary rounded-circle fw-bold"
                              style="width:32px;height:32px;font-size:.75rem;">
                            {{ auth()->user()->initials() }}
                        </span>
                        <div class="lh-sm overflow-hidden">
                            <div class="text-white text-truncate fw-medium" style="max-width:160px;">{{ auth()->user()->name }}</div>
                            <div class="text-muted text-truncate small" style="max-width:160px;">{{ auth()->user()->email }}</div>
                        </div>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="nav-link mb-1" wire:navigate data-bs-dismiss="offcanvas">
                        <i class="bi bi-gear me-2"></i>{{ __('Settings') }}
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-link w-100 text-start bg-transparent border-0 p-0" style="color: rgba(255,255,255,.75)">
                            <i class="bi bi-box-arrow-right me-2"></i>{{ __('Log out') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Toast notifications --}}
        <div
            x-data="{ show: false, message: '', type: 'success' }"
            @notify.window="show = true; message = $event.detail.message; type = $event.detail.type || 'success'; setTimeout(() => show = false, 3500)"
            class="position-fixed top-0 end-0 p-3"
            style="z-index: 9999"
        >
            <div x-show="show" x-transition class="toast show">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-info-circle-fill text-primary'"></i>
                    <span x-text="message"></span>
                </div>
            </div>
        </div>

        {{-- Confirm dialog (replaces native wire:confirm) --}}
        <div
            x-data="{
                modal: null,
                message: '',
                title: @js(__('Bestätigen')),
                confirmText: @js(__('Bestätigen')),
                cancelText: @js(__('Abbrechen')),
                confirmClass: 'btn-danger',
                onConfirm: null,
                show(detail) {
                    this.message = detail.message ?? '';
                    this.title = detail.title ?? @js(__('Bestätigen'));
                    this.confirmText = detail.confirmText ?? @js(__('Bestätigen'));
                    this.confirmClass = detail.confirmClass ?? 'btn-danger';
                    this.onConfirm = detail.onConfirm ?? null;
                    this.modal = this.modal || new bootstrap.Modal(this.$refs.dialog);
                    this.modal.show();
                },
                accept() {
                    const cb = this.onConfirm;
                    this.modal.hide();
                    if (cb) { cb(); }
                }
            }"
            @confirm-dialog.window="show($event.detail)"
        >
            <div class="modal fade" tabindex="-1" x-ref="dialog" wire:ignore>
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" x-text="title"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0" x-text="message" style="white-space:pre-line"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" x-text="cancelText"></button>
                            <button type="button" class="btn" :class="confirmClass" x-text="confirmText" @click="accept()"></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
