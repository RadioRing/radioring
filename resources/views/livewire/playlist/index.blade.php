<div x-data="{
        focusSearch(event) {
            const tag = (event.target.tagName || '').toLowerCase();
            if (['input', 'textarea', 'select'].includes(tag) || document.body.classList.contains('modal-open')) return;
            event.preventDefault();
            this.$refs.search.focus();
            this.$refs.search.select();
        }
     }"
     @keydown.window.slash="focusSearch($event)"
     x-on:playlist-row-highlighted.window="$nextTick(() => document.getElementById('playlist-row-' + $event.detail.id)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-music-note-list me-2 text-primary"></i>{{ __('Playlisten') }}
        </h4>
    </div>

    {{-- Toolbar: stays on top while scrolling --}}
    <div class="list-toolbar mb-3">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <ul class="nav nav-pills small gap-1 flex-nowrap">
                <li class="nav-item">
                    <button class="nav-link py-1 px-2 {{ $tab === 'playlists' ? 'active' : '' }}" wire:click="switchTab('playlists')">
                        <i class="bi bi-collection-play me-1"></i>{{ __('Playlisten') }}
                        <span class="badge {{ $tab === 'playlists' ? 'bg-white text-primary' : 'bg-secondary' }} ms-1">
                            {{ $search !== '' ? $playlists->count() : $totalPlaylists }}
                        </span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link py-1 px-2 {{ $tab === 'containers' ? 'active' : '' }}" wire:click="switchTab('containers')">
                        <i class="bi bi-box-seam me-1"></i>{{ __('Containers') }}
                        <span class="badge {{ $tab === 'containers' ? 'bg-white text-primary' : 'bg-secondary' }} ms-1">
                            {{ $search !== '' ? $containers->count() : $totalContainers }}
                        </span>
                    </button>
                </li>
            </ul>

            <div class="input-group input-group-sm flex-grow-1" style="max-width:340px;min-width:200px">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" x-ref="search"
                       wire:model.live.debounce.250ms="search"
                       @keydown.escape="$wire.$set('search', '')"
                       @keydown.enter.prevent="document.querySelector('[data-first-hit]')?.click()"
                       class="form-control" placeholder="{{ __('Search by name... (press /)') }}">
                @if($search !== '')
                    <button class="btn btn-outline-secondary" wire:click="$set('search', '')" title="{{ __('Clear search') }}">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </div>

            <select wire:model.live="sort" class="form-select form-select-sm" style="width:auto" aria-label="{{ __('Sort') }}">
                <option value="name">{{ __('Sort: name') }}</option>
                <option value="recent">{{ __('Sort: recently changed') }}</option>
            </select>

            <div class="ms-auto d-flex gap-2">
                <button class="btn btn-sm {{ $tab === 'containers' ? 'btn-primary' : 'btn-outline-primary' }}" wire:click="startCreating('container')">
                    <i class="bi bi-plus-lg me-1"></i>{{ __('New container') }}
                </button>
                <button class="btn btn-sm {{ $tab === 'playlists' ? 'btn-primary' : 'btn-outline-primary' }}" wire:click="startCreating('playlist')">
                    <i class="bi bi-plus-lg me-1"></i>{{ __('Neue Playlist') }}
                </button>
            </div>
        </div>
    </div>

    @php
        $isContainerTab = $tab === 'containers';
        $rows = $isContainerTab ? $containers : $playlists;
        $total = $isContainerTab ? $totalContainers : $totalPlaylists;
    @endphp

    @if($isContainerTab)
        <p class="text-muted-sm mb-2">
            {{ __('Reusable blocks of elements. Add them to a playlist instead of scheduling them on the hour grid.') }}
        </p>
    @endif

    {{-- List --}}
    @if($rows->isEmpty())
        <div class="text-center py-5">
            <i class="bi {{ $isContainerTab ? 'bi-box-seam' : 'bi-music-note-beamed' }} display-4 text-muted"></i>
            @if($total > 0)
                <p class="mt-3 text-muted">{{ __('Nothing matches the search.') }}</p>
                <div class="d-flex justify-content-center gap-2">
                    <button class="btn btn-outline-secondary btn-sm" wire:click="$set('search', '')">
                        <i class="bi bi-x-lg me-1"></i>{{ __('Clear search') }}
                    </button>
                    <button class="btn btn-primary btn-sm" wire:click="startCreating('{{ $isContainerTab ? 'container' : 'playlist' }}')">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('Create „:name"', ['name' => $search]) }}
                    </button>
                </div>
            @else
                <p class="mt-3 text-muted">
                    {{ $isContainerTab ? __('No containers yet.') : __('Noch keine Playlisten vorhanden.') }}
                </p>
            @endif
        </div>
    @else
        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($rows as $playlist)
                    <div class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2 {{ $highlightId === $playlist->id ? 'list-row-highlight' : '' }}"
                         id="playlist-row-{{ $playlist->id }}"
                         wire:key="playlist-{{ $playlist->id }}">
                        <i class="bi {{ $isContainerTab ? 'bi-box-seam text-secondary' : 'bi-collection-play text-primary' }} fs-5 flex-shrink-0"></i>

                        {{-- The whole name area opens the editor --}}
                        <a href="{{ route('playlist.manager', $playlist) }}" wire:navigate
                           class="flex-grow-1 overflow-hidden text-reset text-decoration-none"
                           @if($loop->first) data-first-hit @endif>
                            <div class="fw-medium text-truncate">{{ $playlist->name }}</div>
                            <div class="text-muted d-flex flex-wrap align-items-center column-gap-3" style="font-size:.75rem">
                                @unless($isContainerTab)
                                    <span>
                                        <i class="bi bi-{{ $playlist->playback_mode === 'random' ? 'shuffle' : 'list-ol' }} me-1"></i>{{ $playlist->playback_mode === 'random' ? __('Zufällig') : __('Sequentiell') }}
                                    </span>
                                @endunless
                                <span><i class="bi bi-list-ul me-1"></i>{{ trans_choice('{0}empty|{1}1 element|[2,*]:count elements', $playlist->items_count, ['count' => $playlist->items_count]) }}</span>
                                @if($isContainerTab)
                                    <span class="{{ $playlist->embedding_items_count === 0 ? 'text-warning-emphasis' : '' }}">
                                        <i class="bi bi-diagram-3 me-1"></i>{{ trans_choice('{0}not used yet|{1}used once|[2,*]used :count times', $playlist->embedding_items_count, ['count' => $playlist->embedding_items_count]) }}
                                    </span>
                                @else
                                    <span class="{{ $playlist->hour_grid_slots_count === 0 ? 'text-warning-emphasis' : '' }}">
                                        <i class="bi bi-calendar-week me-1"></i>{{ trans_choice('{0}not on the hour grid|{1}1 hour on the grid|[2,*]:count hours on the grid', $playlist->hour_grid_slots_count, ['count' => $playlist->hour_grid_slots_count]) }}
                                    </span>
                                    @if($playlist->startsHard())
                                        <span class="text-danger"><i class="bi bi-clock-fill me-1"></i>{{ __('Harter Start') }}</span>
                                    @endif
                                @endif
                                <span title="{{ $playlist->updated_at?->format('d.m.Y H:i') }}">
                                    <i class="bi bi-pencil-square me-1"></i>{{ __('changed :time', ['time' => $playlist->updated_at?->diffForHumans()]) }}
                                </span>
                            </div>
                        </a>

                        <div class="d-flex gap-1 flex-shrink-0">
                            <a href="{{ route('playlist.manager', $playlist) }}"
                               class="btn btn-sm btn-outline-primary" wire:navigate title="{{ __('Bearbeiten') }}">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="btn btn-sm btn-outline-secondary"
                                    wire:click="duplicate({{ $playlist->id }})"
                                    wire:loading.attr="disabled" wire:target="duplicate"
                                    title="{{ __('Duplizieren') }}">
                                <i class="bi bi-files"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" title="{{ __('Löschen') }}"
                                    @click="$dispatch('confirm-dialog', { message: @js($isContainerTab
                                        ? __('Delete container „:name"? It is removed from every playlist that uses it.', ['name' => $playlist->name])
                                        : __('Playlist „:name" wirklich löschen?', ['name' => $playlist->name])), confirmText: @js(__('Löschen')), onConfirm: () => $wire.delete({{ $playlist->id }}) })">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        @if($search !== '')
            <div class="text-muted-sm mt-2">{{ __(':shown of :total', ['shown' => $rows->count(), 'total' => $total]) }}</div>
        @endif
    @endif

    {{-- Create dialog --}}
    <x-modal size="" open-event="playlist-create-opened" close-event="playlist-create-closed"
             dismiss="$wire.showCreateForm && $wire.cancelCreating()">
        <form wire:submit="create">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi {{ $newKind === 'container' ? 'bi-box-seam' : 'bi-collection-play' }} me-1"></i>
                    {{ $newKind === 'container' ? __('New container') : __('Neue Playlist') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
            </div>
            <div class="modal-body">
                @if($newKind === 'container')
                    <div class="alert alert-info py-2 px-3 small">
                        <i class="bi bi-box-seam me-1"></i>
                        {{ __('A container is a reusable block of elements, for example jingle + news + ad break. Add it to any playlist; it is resolved into its elements when the rundown is generated.') }}
                    </div>
                @endif
                <div class="mb-3">
                    <label class="form-label" for="new-playlist-name">{{ __('Name') }}</label>
                    <input type="text" id="new-playlist-name" wire:model="newName" autofocus
                           class="form-control @error('newName') is-invalid @enderror"
                           placeholder="{{ __('z.B. Morning Show') }}">
                    @error('newName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                @if($newKind !== 'container')
                    <div>
                        <label class="form-label" for="new-playlist-mode">{{ __('Wiedergabemodus') }}</label>
                        <select id="new-playlist-mode" wire:model="newPlaybackMode" class="form-select">
                            <option value="sequential">{{ __('Sequentiell') }}</option>
                            <option value="random">{{ __('Zufällig') }}</option>
                        </select>
                    </div>
                @endif
                <div class="form-text mt-3">{{ __('The editor opens right after creating.') }}</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Abbrechen') }}</button>
                <button type="submit" class="btn btn-primary">
                    <span wire:loading wire:target="create" class="spinner-border spinner-border-sm me-1"></span>
                    {{ __('Erstellen') }}
                </button>
            </div>
        </form>
    </x-modal>
</div>
