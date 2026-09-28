<div x-data="{
        openGroups: {},
        focusSearch(event) {
            const tag = (event.target.tagName || '').toLowerCase();
            if (['input', 'textarea', 'select'].includes(tag) || document.body.classList.contains('modal-open')) return;
            event.preventDefault();
            this.$refs.search.focus();
            this.$refs.search.select();
        },
        {{-- While searching or filtering every group is open, otherwise hits would hide in closed groups. --}}
        isOpen(key) {
            return this.openGroups[key] || this.$wire.search !== '' || this.$wire.filterKind !== '' || this.$wire.filterStatus !== '';
        },
        reveal(element) {
            if (! element) return;
            if (element.dataset.group) this.openGroups[element.dataset.group] = true;
            this.$nextTick(() => element.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
        }
     }"
     @keydown.window.slash="focusSearch($event)"
     x-on:source-row-highlighted.window="setTimeout(() => reveal(document.getElementById('source-row-' + $event.detail.id)))"
     x-on:source-group-imported.window="openGroups[$event.detail.key] = true; setTimeout(() => reveal(document.getElementById('group-' + $event.detail.key)))">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-rss me-2 text-primary"></i>{{ __('Externe Quellen') }}
        </h4>
        <div class="d-flex align-items-center gap-2">
            @if($station->hasSyndicationConnection())
                <div class="btn-group">
                    <button class="btn btn-outline-primary btn-sm" wire:click="startImport" wire:loading.attr="disabled" wire:target="startImport">
                        <span wire:loading wire:target="startImport" class="spinner-border spinner-border-sm me-1"></span>
                        <i wire:loading.remove wire:target="startImport" class="bi bi-cloud-download me-1"></i>{{ __('Syndications importieren') }}
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle dropdown-toggle-split"
                            data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('Syndications4Radio') }}">
                        <span class="visually-hidden">{{ __('Syndications4Radio') }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end small">
                        <li><span class="dropdown-item-text text-success"><i class="bi bi-check-circle me-1"></i>{{ __('Mit Syndications4Radio verbunden') }}</span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button class="dropdown-item"
                                    @click="$dispatch('confirm-dialog', { message: @js(__('Verbindung zu Syndications4Radio trennen? Bereits importierte Quellen bleiben bestehen, können aber keine neuen Dateien mehr abrufen.')), confirmText: @js(__('Trennen')), confirmClass: 'btn-warning', onConfirm: () => $wire.disconnectS4r() })">
                                <i class="bi bi-x-circle me-2"></i>{{ __('Trennen') }}
                            </button>
                        </li>
                    </ul>
                </div>
            @else
                <button class="btn btn-outline-primary btn-sm" wire:click="startConnect">
                    <i class="bi bi-broadcast me-1"></i>{{ __('Syndications4Radio verbinden') }}
                </button>
            @endif
        </div>
    </div>

    <p class="text-muted small mb-2">
        {{ __('Dynamische HTTP-Inhalte (Syndication, laut.fm-Nachrichten/Wetter). Sie werden kurz vor Ausspielung geholt, geprüft und – falls rechtzeitig gemessen – normalisiert. In Playlisten als Element einbindbar.') }}
    </p>

    {{-- Toolbar: search, filters and "add" stay on top while scrolling --}}
    <div class="list-toolbar mb-3">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <div class="input-group input-group-sm flex-grow-1" style="max-width:340px;min-width:200px">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" x-ref="search"
                       wire:model.live.debounce.250ms="search"
                       @keydown.escape="$wire.$set('search', '')"
                       @keydown.enter.prevent="document.querySelector('[data-first-hit]')?.click()"
                       class="form-control" placeholder="{{ __('Search name, address, file... (press /)') }}">
                @if($search !== '')
                    <button class="btn btn-outline-secondary" wire:click="$set('search', '')" title="{{ __('Clear search') }}">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </div>
            <select wire:model.live="filterKind" class="form-select form-select-sm" style="width:auto" aria-label="{{ __('Art') }}">
                <option value="">{{ __('All kinds') }}</option>
                <option value="syndication">{{ __('Syndication') }}</option>
                <option value="url">{{ __('Address') }}</option>
                <option value="news_weather">{{ __('Nachrichten + Wetter') }}</option>
                <option value="news">{{ __('Nachrichten') }}</option>
                <option value="weather">{{ __('Wetter') }}</option>
            </select>
            <select wire:model.live="filterStatus" class="form-select form-select-sm" style="width:auto" aria-label="{{ __('Status') }}">
                <option value="">{{ __('Any status') }}</option>
                <option value="error">{{ __('Only with an error') }}</option>
                <option value="used">{{ __('Only used in playlists') }}</option>
                <option value="unused">{{ __('Only unused') }}</option>
            </select>
            @if($this->hasActiveFilters())
                <span class="text-muted-sm text-nowrap">{{ __(':shown of :total', ['shown' => $sources->count(), 'total' => $totalSources]) }}</span>
                <button class="btn btn-sm btn-link p-0 text-nowrap" wire:click="resetFilters">
                    <i class="bi bi-x-lg me-1"></i>{{ __('Filter aufheben') }}
                </button>
            @else
                <span class="text-muted-sm text-nowrap">{{ trans_choice('{0}no sources|{1}1 source|[2,*]:count sources', $totalSources, ['count' => $totalSources]) }}</span>
            @endif
            <button class="btn btn-primary btn-sm ms-auto" wire:click="startCreate">
                <i class="bi bi-plus-lg me-1"></i>{{ __('Quelle hinzufügen') }}
            </button>
        </div>
    </div>

    {{-- List --}}
    @if($sources->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-rss display-4 text-muted"></i>
            @if($totalSources > 0)
                <p class="mt-3 text-muted">{{ __('No source matches the search or the filters.') }}</p>
                <button class="btn btn-outline-secondary btn-sm" wire:click="resetFilters">
                    <i class="bi bi-x-lg me-1"></i>{{ __('Filter aufheben') }}
                </button>
            @else
                <p class="mt-3 text-muted">{{ __('Noch keine externen Quellen angelegt.') }}</p>
            @endif
        </div>
    @else
        @php
            $kindLabels = ['url' => __('Address'), 'news' => __('Nachrichten'), 'weather' => __('Wetter'), 'news_weather' => __('Nachrichten + Wetter'), 'syndication' => __('Syndication')];
            $kindBadge = ['url' => 'primary', 'syndication' => 'success'];
        @endphp
        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($rows as $row)
                    @if($row['sources']->count() === 1)
                        @include('livewire.external-source.partials.source-row', ['source' => $row['sources']->first(), 'groupKey' => null, 'firstHit' => $loop->first])
                    @else
                        {{-- Files of one imported show, folded --}}
                        @php
                            $groupSources = $row['sources'];
                            $groupErrors = $groupSources->whereNotNull('last_error')->count();
                            $groupUsage = $groupSources->sum('playlist_items_count');
                            $groupUnknown = $groupSources->whereNull('expected_duration_seconds')->count();
                        @endphp
                        <div class="list-group-item list-group-header d-flex align-items-center gap-3 py-2"
                             id="group-{{ $row['key'] }}" wire:key="group-{{ $row['key'] }}"
                             @click="openGroups['{{ $row['key'] }}'] = ! isOpen('{{ $row['key'] }}')"
                             @if($loop->first) data-first-hit @endif>
                            <i class="bi text-muted flex-shrink-0" :class="isOpen('{{ $row['key'] }}') ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                            <span class="badge bg-success text-nowrap flex-shrink-0" style="min-width:110px">{{ __('Syndication') }}</span>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="fw-medium small text-truncate">{{ $row['label'] }}</div>
                                <div class="text-muted" style="font-size:.75rem">
                                    <i class="bi bi-broadcast me-1"></i>{{ __('S4R-Sendung #:id', ['id' => $groupSources->first()->syndication_sendung_id]) }}
                                    <span class="ms-2"><i class="bi bi-files me-1"></i>{{ trans_choice('{1}1 file|[2,*]:count files', $groupSources->count(), ['count' => $groupSources->count()]) }}</span>
                                    @if($groupUnknown > 0)
                                        <span class="ms-2 text-warning-emphasis"><i class="bi bi-clock-history me-1"></i>{{ trans_choice('{1}1 without length|[2,*]:count without length', $groupUnknown, ['count' => $groupUnknown]) }}</span>
                                    @endif
                                    @if($groupErrors > 0)
                                        <span class="ms-2 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ trans_choice('{1}1 with an error|[2,*]:count with an error', $groupErrors, ['count' => $groupErrors]) }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="text-muted-sm text-nowrap text-end flex-shrink-0" style="width:5.5rem">
                                @if($groupUsage > 0)
                                    <i class="bi bi-collection me-1"></i>{{ $groupUsage }}×
                                @else
                                    <span class="text-muted">{{ __('Unbenutzt') }}</span>
                                @endif
                            </span>
                            {{-- Keeps the columns in line with the action buttons of the rows --}}
                            <span class="flex-shrink-0" style="width:4.3rem"></span>
                        </div>
                        <div x-show="isOpen('{{ $row['key'] }}')" x-cloak wire:key="group-body-{{ $row['key'] }}">
                            @foreach($groupSources as $source)
                                @include('livewire.external-source.partials.source-row', ['source' => $source, 'groupKey' => $row['key'], 'firstHit' => false])
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Create / edit dialog --}}
    <x-modal open-event="source-form-opened" close-event="source-form-closed"
             dismiss="$wire.showForm && $wire.cancel()">
        <form wire:submit="save">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-{{ $editingId ? 'pencil' : 'plus-lg' }} me-1"></i>
                    {{ $editingId ? __('Quelle bearbeiten') : __('Neue Quelle') }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-medium" for="source-name">{{ __('Name') }}</label>
                        <input type="text" id="source-name" wire:model="name" autofocus
                               class="form-control form-control-sm @error('name') is-invalid @enderror"
                               placeholder="{{ __('z.B. Morgenshow-Syndication') }}">
                        @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-medium" for="source-broadcast-title">{{ __('Broadcast title') }}</label>
                        <input type="text" id="source-broadcast-title" wire:model="broadcastTitle"
                               class="form-control form-control-sm @error('broadcastTitle') is-invalid @enderror"
                               placeholder="{{ $name !== '' ? $name : __('optional') }}">
                        @error('broadcastTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text" style="font-size:.75rem">
                            {{ __('Leave empty to broadcast the name. Set it to keep internal markers such as #1, #2 out of the stream.') }}
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-medium" for="source-kind">{{ __('Art') }}</label>
                        <select id="source-kind" wire:model.live="kind" class="form-select form-select-sm @error('kind') is-invalid @enderror"
                                @disabled($kind === 'syndication')>
                            <option value="url">{{ __('Address (HTTP/FTP)') }}</option>
                            <option value="news_weather">{{ __('Nachrichten + Wetter (laut.fm)') }}</option>
                            <option value="news">{{ __('Nachrichten (laut.fm)') }}</option>
                            <option value="weather">{{ __('Wetter (laut.fm)') }}</option>
                            @if($kind === 'syndication')
                                <option value="syndication">{{ __('Syndication (aus S4R)') }}</option>
                            @endif
                        </select>
                        @error('kind') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    @if($kind === 'url')
                        <div class="col-12">
                            <label class="form-label small fw-medium" for="source-url">{{ __('Address') }}</label>
                            <input type="text" id="source-url" wire:model="url"
                                   class="form-control form-control-sm @error('url') is-invalid @enderror"
                                   placeholder="https://... {{ __('or') }} ftps://...">
                            @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text" style="font-size:.75rem">
                                {{ __('Allowed: http, https, ftp, ftps. ftps means FTP with mandatory TLS (implicit on port 990, otherwise AUTH TLS).') }}
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-medium" for="source-username">{{ __('User name') }}</label>
                            <input type="text" id="source-username" wire:model="urlUsername" autocomplete="off"
                                   class="form-control form-control-sm @error('urlUsername') is-invalid @enderror"
                                   placeholder="{{ __('optional') }}">
                            @error('urlUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-medium" for="source-password">{{ __('Password') }}</label>
                            <input type="password" id="source-password" wire:model="urlPassword" autocomplete="new-password"
                                   class="form-control form-control-sm @error('urlPassword') is-invalid @enderror"
                                   placeholder="{{ $editingId ? __('leave empty to keep') : __('optional') }}">
                            @error('urlPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text" style="font-size:.75rem">
                                {{ __('Stored encrypted and sent as the FTP login or HTTP basic auth, never as part of the address.') }}
                            </div>
                        </div>
                    @else
                        <div class="col-12">
                            <div class="alert alert-info py-2 small mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                @if($kind === 'syndication')
                                    {{ __('Die Datei wird zur Laufzeit über die Syndications4Radio-Partner-API als signierte URL aufgelöst.') }}
                                @else
                                    {{ __('Die URL wird zur Laufzeit aus den Credentials deines laut.fm-Ausgangs gebaut.') }}
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-medium" for="source-duration">{{ __('Erwartete Dauer (s)') }}</label>
                        <input type="number" id="source-duration" wire:model="expectedDuration" min="1"
                               class="form-control form-control-sm @error('expectedDuration') is-invalid @enderror"
                               placeholder="{{ __('optional') }}">
                        @error('expectedDuration') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-medium" for="source-prefetch">
                            {{ __('Vorlauf (s)') }}
                            <i class="bi bi-question-circle text-muted" title="{{ __('Wie früh vor Ausspielung der Inhalt geholt und analysiert wird.') }}"></i>
                        </label>
                        <input type="number" id="source-prefetch" wire:model="prefetchLead" min="0"
                               class="form-control form-control-sm @error('prefetchLead') is-invalid @enderror">
                        @error('prefetchLead') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-medium" for="source-freshness">
                            {{ __('Frische (s)') }}
                            <i class="bi bi-question-circle text-muted" title="{{ __('Wie lange eine geholte Kopie wiederverwendet werden darf. 0 = im Vorlauf einmal holen und so ausspielen.') }}"></i>
                        </label>
                        <input type="number" id="source-freshness" wire:model="freshness" min="0"
                               class="form-control form-control-sm @error('freshness') is-invalid @enderror">
                        @error('freshness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-12 d-flex flex-wrap column-gap-4 row-gap-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="normalize" id="normalize">
                            <label class="form-check-label small" for="normalize">{{ __('Normalisieren') }}</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="trimLeadingSilence" id="trimLeadingSilence">
                            <label class="form-check-label small" for="trimLeadingSilence">
                                {{ __('Stille am Anfang entfernen') }}
                                <i class="bi bi-question-circle text-muted" title="{{ __('Schneidet beim Vorbereiten führende Stille weg (z.B. das Loch vor dem Nachrichten-Intro).') }}"></i>
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" wire:model="fadeIn" id="fadeIn">
                            <label class="form-check-label small" for="fadeIn">
                                {{ __('Sanft einblenden (Fade-in)') }}
                                <i class="bi bi-question-circle text-muted" title="{{ __('Blendet das Element beim Ausspielen weich ein statt hart einzusetzen.') }}"></i>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('Abbrechen') }}</button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                    <i wire:loading.remove wire:target="save" class="bi bi-floppy me-1"></i>{{ __('Speichern') }}
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Connect Syndications4Radio --}}
    <x-modal size="" open-event="s4r-connect-opened" close-event="s4r-connect-closed"
             dismiss="$wire.showConnect && $wire.cancelConnect()">
        <form wire:submit="connectS4r">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-broadcast me-1 text-primary"></i>{{ __('Syndications4Radio verbinden') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    {{ __('Erzeuge im Radiomanager bei S4R einen Partner-API-Token und füge ihn hier ein. Danach kannst du deine gebuchten Syndications importieren.') }}
                </p>
                <input type="text" wire:model="s4rTokenInput" autofocus
                       class="form-control form-control-sm @error('s4rTokenInput') is-invalid @enderror"
                       placeholder="{{ __('Partner-API-Token') }}">
                @error('s4rTokenInput') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('Abbrechen') }}</button>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-link-45deg me-1"></i>{{ __('Verbinden') }}
                </button>
            </div>
        </form>
    </x-modal>

    {{-- Import wizard --}}
    <x-modal open-event="source-import-opened" close-event="source-import-closed"
             dismiss="$wire.showImport && $wire.cancelImport()">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="bi bi-cloud-download me-1"></i>{{ __('Syndications importieren') }}
                <span class="badge bg-secondary ms-1 align-middle" style="font-size:.7rem">{{ __('Schritt :n/2', ['n' => $importStep]) }}</span>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
        </div>
        <div class="modal-body">
            @if($importError)
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ $importError }}
                </div>
            @endif

            @if($importNotice)
                <div class="alert alert-info py-2 small">
                    <i class="bi bi-info-circle me-1"></i>{{ $importNotice }}
                </div>
            @endif

            @if($importStep === 1)
                @if(empty($importShows) && ! $importError)
                    <p class="text-muted small mb-0">{{ __('Keine genehmigten Sendungen mit abrufbaren Dateien gefunden.') }}</p>
                @elseif(! empty($importShows))
                    <div x-data="{ q: '' }">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <p class="small fw-medium mb-0">{{ __('Welche Sendung möchtest du importieren?') }}</p>
                            <div class="input-group input-group-sm" style="max-width:240px">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" x-model="q" class="form-control" placeholder="{{ __('Filter shows...') }}" autofocus>
                            </div>
                        </div>
                        <div class="list-group">
                            @foreach($importShows as $show)
                                @php $importedVariants = $importedSyndications[$show['id']] ?? []; @endphp
                                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                        wire:key="show-{{ $show['id'] }}"
                                        data-name="{{ mb_strtolower($show['name'].' '.($show['genre'] ?? '')) }}"
                                        x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())"
                                        wire:click="selectImportShow({{ $show['id'] }})">
                                    <i class="bi bi-broadcast text-primary"></i>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div class="fw-medium small text-truncate">
                                            {{ $show['name'] }}
                                            @if($importedVariants)
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis fw-normal ms-1">
                                                    {{ __('already imported: :variants', ['variants' => implode(', ', $importedVariants)]) }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-muted text-truncate" style="font-size:.75rem">
                                            @if(! empty($show['genre'])){{ $show['genre'] }} · @endif
                                            @if(! empty($show['frequency'])){{ $show['frequency'] }} · @endif
                                            {{ __('Varianten:') }} {{ implode(', ', $show['available_variants'] ?? []) }}
                                        </div>
                                    </div>
                                    <i class="bi bi-chevron-right text-muted"></i>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @elseif($importStep === 2 && $importSelectedShow)
                <p class="small mb-1">
                    {{ __('Welche Datei-Variante von') }} <strong>{{ $importSelectedShow['name'] }}</strong> {{ __('möchtest du importieren?') }}
                </p>
                <p class="text-muted mb-3" style="font-size:.78rem">
                    <i class="bi bi-info-circle me-1"></i>{{ __('Enthält die Sendung mehrere Dateien, wird pro Datei eine eigene Quelle angelegt.') }}
                    {{ __('Files that are already here are skipped, so importing again only picks up what is new.') }}
                </p>
                <div class="d-flex flex-column gap-2" style="max-width:480px">
                    @foreach(($importSelectedShow['available_variants'] ?? []) as $variant)
                        <label class="list-group-item d-flex align-items-center gap-2 border rounded px-3 py-2">
                            <input class="form-check-input mt-0" type="radio" wire:model="importVariant" value="{{ $variant }}">
                            <span class="fw-medium">{{ $variant === 'lfm' ? __('laut.fm-Dateien') : __('Standard-Dateien') }}</span>
                            <span class="text-muted small">({{ $variant }})</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
        @if($importStep === 2 && $importSelectedShow)
            <div class="modal-footer">
                <button class="btn btn-outline-secondary btn-sm me-auto" wire:click="backToShowList">
                    <i class="bi bi-arrow-left me-1"></i>{{ __('Zurück') }}
                </button>
                <button class="btn btn-primary btn-sm" wire:click="importShow" wire:loading.attr="disabled" wire:target="importShow">
                    <span wire:loading wire:target="importShow" class="spinner-border spinner-border-sm me-1"></span>
                    <i wire:loading.remove wire:target="importShow" class="bi bi-plus-lg me-1"></i>{{ __('Importieren') }}
                </button>
            </div>
        @endif
    </x-modal>
</div>
