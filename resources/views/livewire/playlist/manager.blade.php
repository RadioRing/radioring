<div x-data="{
        focusSearch(event) {
            const tag = (event.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            event.preventDefault();
            this.$refs.paletteSearch?.focus();
        }
     }"
     @keydown.window.slash="focusSearch($event)"
     @keydown.window.escape="$wire.clearPicks(); $wire.clearSelection()">

    {{-- Kopfzeile: Titel, Laufzeit, Einstellungen --}}
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-3">
        <div>
            <h4 class="fw-semibold mb-0">
                {{ $playlist->name }}
                @if($playlist->isContainer())
                    <span class="badge bg-secondary align-middle ms-1">{{ __('Container') }}</span>
                @endif
            </h4>
            <a href="{{ route('playlist.index') }}" class="text-muted-sm text-decoration-none" wire:navigate>
                <i class="bi bi-arrow-left me-1"></i>{{ __('Alle Playlisten') }}
            </a>
        </div>

        <div class="d-flex align-items-center gap-3 flex-wrap">
            {{-- Laufzeit: die wichtigste Frage beim Bauen einer Stundenplaylist --}}
            <div style="min-width:190px">
                <div class="d-flex align-items-center justify-content-between gap-2 text-muted-sm">
                    <span>{{ __('Runtime') }}</span>
                    <span class="fw-medium {{ $playlist->isContainer() || $runtime->fitsInHour() ? '' : 'text-danger' }}">
                        {{ $runtime::format($runtime->total()) }}@if(! $playlist->isContainer()) / 60:00 @endif
                    </span>
                </div>
                @unless($playlist->isContainer())
                    <div class="progress mt-1" style="height:4px">
                        <div class="progress-bar {{ $runtime->fitsInHour() ? 'bg-primary' : 'bg-danger' }}"
                             style="width: {{ $runtime->hourPercentage() }}%"></div>
                    </div>
                @endunless
                <div class="text-muted" style="font-size:.7rem">
                    @if($runtime->hasOpenEnd())
                        <i class="bi bi-hourglass-split me-1"></i>{{ __('plus up to :time of fill music', ['time' => $runtime::format($runtime->fillBudget())]) }}
                    @elseif($runtime->unknownCount() > 0)
                        <i class="bi bi-question-circle me-1"></i>{{ trans_choice('{1}1 element of unknown length|[2,*]:count elements of unknown length', $runtime->unknownCount(), ['count' => $runtime->unknownCount()]) }}
                    @elseif(! $playlist->isContainer() && $runtime->fitsInHour())
                        {{ __(':time left in the hour', ['time' => $runtime::format($runtime->remainingInHour())]) }}
                    @elseif(! $playlist->isContainer())
                        <span class="text-danger">{{ __('The hour is overbooked.') }}</span>
                    @endif
                </div>
            </div>

            <button class="btn btn-sm {{ $showSettings ? 'btn-secondary' : 'btn-outline-secondary' }}"
                    wire:click="$toggle('showSettings')">
                <i class="bi bi-gear me-1"></i>{{ __('Einstellungen') }}
            </button>
        </div>
    </div>

    {{-- Einstellungen, eingeklappt bis man sie braucht --}}
    @if($showSettings)
        <div class="card mb-3">
            <div class="card-body">
                <form wire:submit="saveSettings">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label form-label-sm">{{ __('Name') }}</label>
                            <input type="text" wire:model="name"
                                   class="form-control form-control-sm @error('name') is-invalid @enderror">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        @if($playlist->isContainer())
                            <div class="col-12 col-md-8">
                                <div class="alert alert-info py-2 px-3 small mb-0">
                                    <i class="bi bi-box-seam me-1"></i>
                                    {{ __('This block is played wherever a playlist embeds it. Playback and start mode come from that playlist.') }}
                                </div>
                            </div>
                        @else
                            <div class="col-12 col-md-3">
                                <label class="form-label form-label-sm">{{ __('Wiedergabemodus') }}</label>
                                <select wire:model="playbackMode" class="form-select form-select-sm">
                                    <option value="sequential">{{ __('Sequentiell') }}</option>
                                    <option value="random">{{ __('Zufällig') }}</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label form-label-sm">{{ __('Start zur vollen Stunde') }}</label>
                                <select wire:model="startMode" class="form-select form-select-sm">
                                    <option value="soft">{{ __('Weich – Überhang der Vorstunde darf auslaufen') }}</option>
                                    <option value="hard">{{ __('Hart – schneidet zur vollen Stunde') }}</option>
                                </select>
                            </div>
                        @endif

                        <div class="col-12 col-md-1">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-check-lg"></i>
                            </button>
                        </div>
                    </div>
                    @unless($playlist->isContainer())
                        <div class="form-text">{{ __('Gilt für jeden Raster-Slot mit dieser Playlist.') }}</div>
                    @endunless
                </form>
            </div>
        </div>
    @endif

    <div class="row g-3 align-items-start">

        {{-- Linke Spalte: Palette --}}
        <div class="col-12 col-lg-5 col-xl-4">
            <div class="card">
                <div class="card-header py-2">
                    @php
                        $tabs = ['media' => __('Medien'), 'container' => __('Container'), 'external' => __('Extern'), 'special' => __('Special')];
                        if ($playlist->isContainer()) { unset($tabs['container']); }
                    @endphp
                    <ul class="nav nav-pills nav-fill small gap-1">
                        @foreach($tabs as $tabKey => $tabLabel)
                            <li class="nav-item">
                                <button class="nav-link py-1 px-2 {{ $paletteTab === $tabKey ? 'active' : '' }}"
                                        wire:click="switchTab('{{ $tabKey }}')">
                                    {{ $tabLabel }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card-body py-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" x-ref="paletteSearch"
                               wire:model.live.debounce.250ms="paletteSearch"
                               class="form-control" placeholder="{{ __('Search everything...') }}">
                        @if($paletteSearch !== '')
                            <button class="btn btn-outline-secondary" wire:click="$set('paletteSearch', '')">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        @endif
                    </div>

                    @if($paletteTab === 'media')
                        <div class="d-flex align-items-center gap-2 mt-2">
                            <select wire:model.live="paletteMediaType" class="form-select form-select-sm" style="max-width:130px">
                                <option value="">{{ __('All types') }}</option>
                                <option value="music">{{ __('Musik') }}</option>
                                <option value="jingle">{{ __('Jingle') }}</option>
                            </select>
                            <button class="btn btn-sm btn-outline-primary ms-auto"
                                    wire:click="$set('paletteForm', '{{ $paletteForm === 'upload' ? '' : 'upload' }}')">
                                <i class="bi bi-cloud-upload me-1"></i>{{ __('Neu hochladen') }}
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Upload-Formular --}}
                @if($paletteForm === 'upload')
                    <div class="card-body border-top bg-light py-3">
                        <form wire:submit="submitUpload">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">{{ __('Titel') }}</label>
                                <input type="text" wire:model="uploadTitle"
                                       class="form-control form-control-sm @error('uploadTitle') is-invalid @enderror">
                                @error('uploadTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-5">
                                    <label class="form-label form-label-sm">{{ __('Typ') }}</label>
                                    <select wire:model="uploadType" class="form-select form-select-sm">
                                        <option value="jingle">{{ __('Jingle') }}</option>
                                        <option value="music">{{ __('Musik') }}</option>
                                    </select>
                                </div>
                                <div class="col-7">
                                    <label class="form-label form-label-sm">{{ __('MP3-Datei') }}</label>
                                    <input type="file" wire:model="uploadFile" accept=".mp3,.m4a,.ogg,.wav,.flac"
                                           class="form-control form-control-sm @error('uploadFile') is-invalid @enderror">
                                    @error('uploadFile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                            <div wire:loading wire:target="uploadFile" class="form-text text-muted">
                                <i class="bi bi-arrow-repeat me-1"></i>{{ __('Wird hochgeladen...') }}
                            </div>
                            <div class="form-text">{{ __('The file is added to the library, analysed and normalised like any other upload.') }}</div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" class="btn btn-sm btn-success"
                                        wire:loading.attr="disabled" wire:target="submitUpload,uploadFile">
                                    <span wire:loading wire:target="submitUpload" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ __('Hinzufügen') }}
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="$set('paletteForm', '')">{{ __('Abbrechen') }}</button>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- URL-Formular (Legacy) --}}
                @if($paletteForm === 'url')
                    <div class="card-body border-top bg-light py-3">
                        <form wire:submit="submitUrl">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">{{ __('Titel') }}</label>
                                <input type="text" wire:model="urlTitle"
                                       class="form-control form-control-sm @error('urlTitle') is-invalid @enderror">
                                @error('urlTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">URL</label>
                                <input type="url" wire:model="urlAddress" placeholder="https://..."
                                       class="form-control form-control-sm @error('urlAddress') is-invalid @enderror">
                                @error('urlAddress') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-2" style="max-width:140px">
                                <label class="form-label form-label-sm">{{ __('Dauer (Sek.)') }}</label>
                                <input type="number" wire:model="urlDuration" min="1"
                                       class="form-control form-control-sm @error('urlDuration') is-invalid @enderror">
                                @error('urlDuration') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-success">{{ __('Hinzufügen') }}</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="$set('paletteForm', '')">{{ __('Abbrechen') }}</button>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- Trefferliste --}}
                <div class="list-group list-group-flush" style="max-height:52vh;overflow-y:auto"
                     x-data
                     x-init="
                         Sortable.create($el, {
                             group: { name: 'playlist-elements', pull: 'clone', put: false },
                             sort: false,
                             animation: 150,
                         })
                     ">
                    @forelse($paletteEntries as $entry)
                        @php $picked = in_array($entry->key(), $picks, true); @endphp
                        <div class="list-group-item d-flex align-items-center gap-2 py-2 {{ $picked ? 'bg-primary-subtle' : '' }}"
                             wire:key="entry-{{ $entry->key() }}"
                             data-palette-key="{{ $entry->key() }}"
                             style="cursor:grab">
                            <button type="button" class="btn btn-sm p-0 border-0 bg-transparent"
                                    wire:click="togglePick('{{ $entry->key() }}')"
                                    title="{{ __('Tick for inserting') }}">
                                <i class="bi {{ $picked ? 'bi-check-square-fill text-primary' : 'bi-square' }}"></i>
                            </button>

                            <span class="badge {{ $entry->badgeClass }} text-nowrap" style="min-width:52px;font-size:.65rem">
                                {{ $entry->badge }}
                            </span>

                            <div class="flex-grow-1 overflow-hidden" style="cursor:pointer"
                                 wire:click="togglePick('{{ $entry->key() }}')">
                                <div class="text-truncate small">{{ $entry->title }}</div>
                                @if($entry->subtitle)
                                    <div class="text-muted text-truncate" style="font-size:.7rem">{{ $entry->subtitle }}</div>
                                @endif
                            </div>

                            @if($entry->durationFormatted())
                                <span class="text-muted text-nowrap" style="font-size:.7rem">{{ $entry->durationFormatted() }}</span>
                            @endif

                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1"
                                    wire:click="insertEntry('{{ $entry->key() }}')"
                                    title="{{ __('Append to the end') }}">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    @empty
                        <div class="list-group-item text-center text-muted small py-4">
                            @if($paletteTab === 'external')
                                {{ __('Noch keine externen Quellen angelegt.') }}
                                <a href="{{ route('external-source.index') }}" wire:navigate>{{ __('Jetzt anlegen') }}</a>
                            @elseif($paletteTab === 'container')
                                {{ __('No containers yet. Create one on the playlist overview.') }}
                            @else
                                {{ __('Nothing matches the search.') }}
                            @endif
                        </div>
                    @endforelse
                </div>

                <div class="card-footer py-2 d-flex align-items-center gap-2 flex-wrap">
                    @if($hasMoreEntries)
                        <button class="btn btn-sm btn-link p-0" wire:click="loadMore">
                            <i class="bi bi-arrow-down-circle me-1"></i>{{ __('Show more') }}
                        </button>
                    @endif

                    @if($paletteTab === 'special')
                        <button class="btn btn-sm btn-link p-0"
                                wire:click="$set('paletteForm', '{{ $paletteForm === 'url' ? '' : 'url' }}')">
                            <i class="bi bi-link-45deg me-1"></i>{{ __('URL (Legacy)') }}
                        </button>
                    @endif

                    <div class="ms-auto d-flex align-items-center gap-2">
                        @if(count($picks) > 0)
                            <button class="btn btn-sm btn-link text-muted p-0" wire:click="clearPicks">
                                {{ __('Clear selection') }}
                            </button>
                            <button class="btn btn-sm btn-success" wire:click="insertPicks">
                                <i class="bi bi-plus-lg me-1"></i>{{ trans_choice('{1}Insert 1 element|[2,*]Insert :count elements', count($picks), ['count' => count($picks)]) }}
                            </button>
                        @else
                            <span class="text-muted" style="font-size:.7rem">
                                {{ __('Tick entries or drag them into the playlist.') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Rechte Spalte: die Playlist --}}
        <div class="col-12 col-lg-7 col-xl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="fw-medium">{{ __('Elemente') }} ({{ $items->count() }})</span>

                    @if(count($selectedItemIds) > 0)
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted-sm">
                                {{ trans_choice('{1}1 selected|[2,*]:count selected', count($selectedItemIds), ['count' => count($selectedItemIds)]) }}
                            </span>
                            <button class="btn btn-sm btn-outline-secondary" wire:click="duplicateSelected">
                                <i class="bi bi-files me-1"></i>{{ __('Duplizieren') }}
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                    @click="$dispatch('confirm-dialog', { message: @js(trans_choice('{1}Remove the selected element?|[2,*]Remove :count selected elements?', count($selectedItemIds), ['count' => count($selectedItemIds)])), confirmText: @js(__('Entfernen')), onConfirm: () => $wire.removeSelected() })">
                                <i class="bi bi-trash me-1"></i>{{ __('Entfernen') }}
                            </button>
                            <button class="btn btn-sm btn-link text-muted" wire:click="clearSelection">
                                {{ __('Clear selection') }}
                            </button>
                        </div>
                    @elseif($items->isNotEmpty())
                        <button class="btn btn-sm btn-link text-muted" wire:click="selectAllItems">
                            {{ __('Select all') }}
                        </button>
                    @endif
                </div>

                {{-- Element-Liste mit Drag & Drop --}}
                <div class="list-group list-group-flush"
                     id="sortable-items"
                     x-data
                     x-init="
                         Sortable.create($el, {
                             group: { name: 'playlist-elements', pull: false, put: true },
                             handle: '.drag-handle',
                             animation: 150,
                             ghostClass: 'opacity-50',
                             onUpdate() {
                                 const ids = [...$el.children]
                                     .map(el => parseInt(el.dataset.itemId))
                                     .filter(id => !isNaN(id));
                                 $wire.reorder(ids);
                             },
                             onAdd(evt) {
                                 const key = evt.item.dataset.paletteKey;
                                 const index = evt.newIndex;
                                 // Den geklonten Knoten entfernen: die Liste rendert gleich
                                 // vom Server neu, sonst stuende er doppelt da.
                                 evt.item.remove();
                                 if (key) { $wire.insertEntryAt(key, index); }
                             },
                         })
                     ">
                    @forelse($items as $item)
                        <div wire:key="item-{{ $item->id }}" data-item-id="{{ $item->id }}">
                            <div class="list-group-item d-flex align-items-center gap-2 gap-md-3 py-2">

                                {{-- Auswahl für die Sammelaktionen --}}
                                <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                       value="{{ $item->id }}" wire:model.live="selectedItemIds"
                                       aria-label="{{ __('Select element') }}">

                                {{-- Drag-Handle --}}
                                <i class="bi bi-grip-vertical text-muted drag-handle" style="cursor:grab"></i>

                                {{-- Startzeit ab Playlist-Beginn --}}
                                <span class="text-muted font-monospace d-none d-sm-inline text-nowrap" style="font-size:.72rem;min-width:44px">
                                    @if($runtime->offset($item) !== null)
                                        {{ $runtime::format($runtime->offset($item)) }}
                                    @else
                                        <span class="opacity-50">--:--</span>
                                    @endif
                                </span>

                                {{-- Typ-Badge --}}
                                @php
                                    $badgeClass = match($item->type) {
                                        'music'        => 'bg-primary',
                                        'jingle'       => 'bg-warning text-dark',
                                        'url'          => 'bg-info text-dark',
                                        'external'     => 'bg-info text-dark',
                                        'fill'         => 'bg-success',
                                        'random'       => 'bg-dark',
                                        'container'    => 'bg-secondary',
                                        'adbreak'      => 'bg-danger',
                                        'news', 'weather', 'news_weather' => 'bg-info text-dark',
                                        default        => 'bg-secondary',
                                    };
                                    $badgeLabel = match($item->type) {
                                        'music'        => __('Musik'),
                                        'jingle'       => __('Jingle'),
                                        'url'          => 'URL',
                                        'external'     => __('Extern'),
                                        'fill'         => __('Fill'),
                                        'random'       => __('Zufall'),
                                        'container'    => __('Container'),
                                        'adbreak'      => __('Ad Break'),
                                        'news'         => __('News'),
                                        'weather'      => __('Wetter'),
                                        'news_weather' => __('News+Wetter'),
                                        default        => $item->type,
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }} text-nowrap" style="min-width:52px">
                                    {{ $badgeLabel }}
                                </span>

                                {{-- Titel + Info --}}
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="text-truncate fw-medium small">@if (isset($item->id))#{{ $item->id }}: @endif{{ $item->title }}</div>
                                    <div class="text-muted d-flex flex-wrap gap-2" style="font-size:.75rem">
                                        @if($item->type === 'adbreak')
                                            <span class="fst-italic"><i class="bi bi-megaphone me-1"></i>{{ __('laut.fm START_AD_BREAK') }}</span>
                                        @elseif(in_array($item->type, ['news', 'weather', 'news_weather']))
                                            <span class="fst-italic"><i class="bi bi-newspaper me-1"></i>{{ __('laut.fm RadioAdmin') }}</span>
                                        @elseif($item->type === 'container')
                                            <span class="fst-italic"><i class="bi bi-box-seam me-1"></i>{{ $item->containerPlaylist?->name ?? __('missing container') }}</span>
                                            @if($item->containerPlaylist)
                                                <a href="{{ route('playlist.manager', $item->containerPlaylist) }}" wire:navigate>{{ __('Bearbeiten') }}</a>
                                            @endif
                                        @elseif($item->type === 'external')
                                            <span class="fst-italic"><i class="bi bi-rss me-1"></i>{{ $item->externalSource?->name ?? __('externe Quelle') }}</span>
                                            @if($item->relative_offset_seconds !== null)
                                                <span class="text-warning-emphasis"><i class="bi bi-stopwatch me-1"></i>{{ $this->formatOffset($item->relative_offset_seconds) }}</span>
                                            @endif
                                        @elseif($item->type === 'fill')
                                            @if($item->fill_tags && count($item->fill_tags) > 0)
                                                @php $tagNames = $stationTags->whereIn('id', $item->fill_tags)->pluck('name'); @endphp
                                                <span><i class="bi bi-tags me-1"></i>{{ $tagNames->join(', ') }}</span>
                                            @else
                                                <span class="fst-italic">{{ __('alle Musik der Station') }}</span>
                                            @endif
                                            @if($item->fill_max_duration_seconds)
                                                <span><i class="bi bi-hourglass-split me-1"></i>max. {{ $this->formatOffset($item->fill_max_duration_seconds) }}</span>
                                            @endif
                                        @elseif($item->type === 'random')
                                            <span class="fst-italic"><i class="bi bi-shuffle me-1"></i>{{ __('zufällig je Rundown') }}</span>
                                            @if($item->fill_tags && count($item->fill_tags) > 0)
                                                @php $tagNames = $stationTags->whereIn('id', $item->fill_tags)->pluck('name'); @endphp
                                                <span><i class="bi bi-tags me-1"></i>{{ $tagNames->join(', ') }}</span>
                                            @else
                                                <span class="fst-italic">{{ __('beliebiges Medium') }}</span>
                                            @endif
                                        @else
                                            @if($item->filename())
                                                <span><i class="bi bi-file-earmark-music me-1"></i>{{ $item->filename() }}</span>
                                            @elseif($item->url)
                                                <span class="text-truncate d-inline-block" style="max-width:200px; vertical-align:bottom">
                                                    <i class="bi bi-link-45deg me-1"></i>{{ $item->url }}
                                                </span>
                                            @endif
                                            @if($item->relative_offset_seconds !== null)
                                                <span class="text-warning-emphasis">
                                                    <i class="bi bi-stopwatch me-1"></i>{{ $this->formatOffset($item->relative_offset_seconds) }}
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                {{-- Länge --}}
                                <span class="text-muted d-none d-md-inline text-nowrap" style="font-size:.75rem">
                                    @if($runtime->duration($item))
                                        <i class="bi bi-clock me-1"></i>{{ $runtime::format($runtime->duration($item)) }}
                                    @endif
                                </span>

                                {{-- Bearbeiten (nicht bei adbreak/news/weather/container – nichts zu konfigurieren) --}}
                                @if(! in_array($item->type, ['adbreak', 'news', 'weather', 'news_weather', 'container']))
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="startEditingItem({{ $item->id }})"
                                            title="{{ __('Bearbeiten') }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                @endif

                                {{-- Duplizieren --}}
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="duplicateItem({{ $item->id }})"
                                        wire:loading.attr="disabled" wire:target="duplicateItem"
                                        title="{{ __('Duplizieren') }}">
                                    <i class="bi bi-files"></i>
                                </button>

                                {{-- Löschen --}}
                                <button class="btn btn-sm btn-outline-danger"
                                        @click="$dispatch('confirm-dialog', { message: @js(__('Element entfernen?')), confirmText: @js(__('Entfernen')), onConfirm: () => $wire.removeItem({{ $item->id }}) })">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>

                            {{-- Inline-Editor --}}
                            @if($editingItemId === $item->id)
                                <div class="list-group-item bg-light border-top-0 py-3 px-3">
                                    @if($item->type === 'fill')
                                        <p class="small fw-medium mb-2">{{ __('Fill-Element bearbeiten') }}</p>
                                        <div class="mb-2">
                                            <label class="form-label form-label-sm">
                                                {{ __('Tags filtern') }}
                                                <span class="text-muted fw-normal">{{ __('(leer = alle Musik)') }}</span>
                                            </label>
                                            @if($stationTags->isEmpty())
                                                <p class="text-muted small mb-0">{{ __('Noch keine Tags angelegt.') }}</p>
                                            @else
                                                <div class="d-flex flex-wrap gap-2">
                                                    @foreach($stationTags as $tag)
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox"
                                                                   id="edit-tag-{{ $item->id }}-{{ $tag->id }}"
                                                                   value="{{ $tag->id }}"
                                                                   wire:model="editFillTagIds">
                                                            <label class="form-check-label small" for="edit-tag-{{ $item->id }}-{{ $tag->id }}">
                                                                {{ $tag->name }}
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                        <div class="mb-3" style="max-width:200px">
                                            <label class="form-label form-label-sm">{{ __('Max. Füll-Dauer (Sek.)') }}</label>
                                            <input type="number" wire:model="editFillMaxDuration"
                                                   class="form-control form-control-sm @error('editFillMaxDuration') is-invalid @enderror"
                                                   placeholder="{{ __('leer = bis zu 60 Minuten') }}" min="60" max="7200">
                                            @error('editFillMaxDuration') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                    @elseif($item->type === 'random')
                                        <p class="small fw-medium mb-2">{{ __('Zufalls-Element bearbeiten') }}</p>
                                        <div class="mb-3">
                                            <label class="form-label form-label-sm">
                                                {{ __('Tags filtern') }}
                                                <span class="text-muted fw-normal">{{ __('(leer = beliebiges Medium)') }}</span>
                                            </label>
                                            @if($stationTags->isEmpty())
                                                <p class="text-muted small mb-0">{{ __('Noch keine Tags angelegt.') }}</p>
                                            @else
                                                <div class="d-flex flex-wrap gap-2">
                                                    @foreach($stationTags as $tag)
                                                        <div class="form-check form-check-inline mb-0">
                                                            <input class="form-check-input" type="checkbox"
                                                                   id="edit-random-tag-{{ $item->id }}-{{ $tag->id }}"
                                                                   value="{{ $tag->id }}"
                                                                   wire:model="editFillTagIds">
                                                            <label class="form-check-label small" for="edit-random-tag-{{ $item->id }}-{{ $tag->id }}">
                                                                {{ $tag->name }}
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <p class="small fw-medium mb-2">{{ __('Element bearbeiten') }}: <span class="fw-normal">{{ $item->title }}</span></p>
                                        <div class="mb-3" style="max-width:180px">
                                            <label class="form-label form-label-sm">
                                                <i class="bi bi-stopwatch me-1"></i>{{ __('Zeitstempel (MM:SS)') }}
                                            </label>
                                            <input type="text" wire:model="editRelativeOffset"
                                                   class="form-control form-control-sm"
                                                   placeholder="{{ __('leer = kein Zeitstempel') }}">
                                            <div class="form-text">{{ __('Zeitpunkt ab Playlist-Start, z.B. 15:00') }}</div>
                                        </div>
                                    @endif
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-primary" wire:click="saveItem">
                                            <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary" wire:click="cancelEditingItem">
                                            {{ __('Abbrechen') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-center text-muted py-5">
                            <i class="bi bi-arrow-left me-1"></i>{{ __('Pick elements on the left, or drag them in here.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
