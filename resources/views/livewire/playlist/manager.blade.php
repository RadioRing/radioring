<div>
    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-semibold mb-0">{{ $playlist->name }}</h4>
            <a href="{{ route('playlist.index') }}" class="text-muted-sm text-decoration-none" wire:navigate>
                <i class="bi bi-arrow-left me-1"></i>{{ __('Alle Playlisten') }}
            </a>
        </div>
    </div>

    <div class="row g-4">
        {{-- Linke Spalte: Einstellungen --}}
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header fw-medium">{{ __('Einstellungen') }}</div>
                <div class="card-body">
                    <form wire:submit="saveSettings">
                        <div class="mb-3">
                            <label class="form-label">{{ __('Name') }}</label>
                            <input type="text" wire:model="name"
                                   class="form-control @error('name') is-invalid @enderror">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Wiedergabemodus') }}</label>
                            <select wire:model="playbackMode" class="form-select">
                                <option value="sequential">{{ __('Sequentiell') }}</option>
                                <option value="random">{{ __('Zufällig') }}</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Start zur vollen Stunde') }}</label>
                            <select wire:model="startMode" class="form-select">
                                <option value="soft">{{ __('Weich – Überhang der Vorstunde darf auslaufen') }}</option>
                                <option value="hard">{{ __('Hart – schneidet zur vollen Stunde') }}</option>
                            </select>
                            <div class="form-text">{{ __('Gilt für jeden Raster-Slot mit dieser Playlist.') }}</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Rechte Spalte: Tracks --}}
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span class="fw-medium">{{ __('Elemente') }} ({{ $items->count() }})</span>
                    <button class="btn btn-sm btn-primary" wire:click="$set('showAddForm', true)">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('Hinzufügen') }}
                    </button>
                </div>

                {{-- Element hinzufügen --}}
                @if($showAddForm)
                    <div class="card-body border-bottom bg-light">
                        <form wire:submit="addItem">

                            {{-- Typ-Auswahl --}}
                            <div class="row g-2 mb-3">
                                <div class="col-sm-4">
                                    <label class="form-label form-label-sm">{{ __('Typ') }}</label>
                                    <select wire:model.live="newType" class="form-select form-select-sm">
                                        <option value="music">{{ __('Musik') }}</option>
                                        <option value="jingle">{{ __('Jingle') }}</option>
                                        <option value="external">{{ __('Externe Quelle') }}</option>
                                        <option value="url">{{ __('URL (Legacy)') }}</option>
                                        <option value="fill">{{ __('Auffüllen mit Musik') }}</option>
                                        <option value="random">{{ __('Zufälliges Element') }}</option>
                                        <option value="adbreak">{{ __('Werbeunterbrechung (laut.fm)') }}</option>
                                    </select>
                                </div>

                                @if(! in_array($newType, ['url', 'external', 'fill', 'adbreak', 'random']))
                                    <div class="col-sm-8 d-flex align-items-end">
                                        <div class="btn-group btn-group-sm w-100" role="group">
                                            <button type="button"
                                                    wire:click="$set('addMode', 'library')"
                                                    class="btn {{ $addMode === 'library' ? 'btn-primary' : 'btn-outline-primary' }}">
                                                <i class="bi bi-collection me-1"></i>{{ __('Aus Bibliothek') }}
                                            </button>
                                            <button type="button"
                                                    wire:click="$set('addMode', 'upload')"
                                                    class="btn {{ $addMode === 'upload' ? 'btn-primary' : 'btn-outline-primary' }}">
                                                <i class="bi bi-cloud-upload me-1"></i>{{ __('Neu hochladen') }}
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- Adbreak-Info --}}
                            @if($newType === 'adbreak')
                                <div class="alert alert-info py-2 px-3 small mb-2">
                                    <i class="bi bi-megaphone me-1"></i>
                                    {{ __('Fügt einen laut.fm-Werbeblock-Marker (START_AD_BREAK) in die Sendung ein. Liquidsoap signalisiert laut.fm an dieser Position den Start des Werbeblocks.') }}
                                </div>

                            {{-- Zufälliges Element: Tag-Auswahl --}}
                            @elseif($newType === 'random')
                                <div class="alert alert-info py-2 px-3 small mb-2">
                                    <i class="bi bi-shuffle me-1"></i>
                                    {{ __('Wählt bei jeder Rundown-Generierung genau ein zufälliges Medium aus den gewählten Tags – z.B. um zufällige Jingles zu platzieren.') }}
                                </div>
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">
                                        {{ __('Tags filtern') }}
                                        <span class="text-muted fw-normal">{{ __('(leer = beliebiges Medium der Station)') }}</span>
                                    </label>
                                    @if($stationTags->isEmpty())
                                        <p class="text-muted small">
                                            {{ __('Noch keine Tags angelegt.') }}
                                            <a href="{{ route('media.index') }}" wire:navigate>{{ __('Tags verwalten') }}</a>
                                        </p>
                                    @else
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach($stationTags as $tag)
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="random-tag-{{ $tag->id }}"
                                                           value="{{ $tag->id }}"
                                                           wire:model="newFillTagIds">
                                                    <label class="form-check-label small" for="random-tag-{{ $tag->id }}">
                                                        {{ $tag->name }}
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                            {{-- Fill-Felder --}}
                            @elseif($newType === 'fill')
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">
                                        {{ __('Tags filtern') }}
                                        <span class="text-muted fw-normal">{{ __('(leer = alle Musik der Station)') }}</span>
                                    </label>
                                    @if($stationTags->isEmpty())
                                        <p class="text-muted small">
                                            {{ __('Noch keine Tags angelegt.') }}
                                            <a href="{{ route('media.index') }}" wire:navigate>{{ __('Tags verwalten') }}</a>
                                        </p>
                                    @else
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach($stationTags as $tag)
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="fill-tag-{{ $tag->id }}"
                                                           value="{{ $tag->id }}"
                                                           wire:model="newFillTagIds">
                                                    <label class="form-check-label small" for="fill-tag-{{ $tag->id }}">
                                                        {{ $tag->name }}
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div class="mb-2" style="max-width:200px">
                                    <label class="form-label form-label-sm">{{ __('Max. Füll-Dauer (Sek.)') }}</label>
                                    <input type="number" wire:model="newFillMaxDuration"
                                           class="form-control form-control-sm @error('newFillMaxDuration') is-invalid @enderror"
                                           placeholder="{{ __('leer = bis Stunden-Ende') }}" min="60" max="7200">
                                    @error('newFillMaxDuration') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>

                            {{-- Externe Quelle wählen --}}
                            @elseif($newType === 'external')
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">{{ __('Externe Quelle') }}</label>
                                    @if($externalSources->isEmpty())
                                        <p class="text-muted small mb-0">
                                            {{ __('Noch keine externen Quellen angelegt.') }}
                                            <a href="{{ route('external-source.index') }}" wire:navigate>{{ __('Jetzt anlegen') }}</a>
                                        </p>
                                    @else
                                        <select wire:model="newExternalSourceId"
                                                class="form-select form-select-sm @error('newExternalSourceId') is-invalid @enderror">
                                            <option value="">{{ __('– wählen –') }}</option>
                                            @foreach($externalSources as $source)
                                                <option value="{{ $source->id }}">{{ $source->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('newExternalSourceId') <div class="invalid-feedback">{{ __('Bitte eine Quelle auswählen.') }}</div> @enderror
                                        <div class="form-text">
                                            {{ __('Dynamischer Inhalt – wird kurz vor Ausspielung geholt und (falls möglich) normalisiert.') }}
                                        </div>
                                    @endif
                                </div>

                            {{-- URL-Felder (Legacy) --}}
                            @elseif($newType === 'url')
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">{{ __('Titel') }}</label>
                                    <input type="text" wire:model="newTitle"
                                           class="form-control form-control-sm @error('newTitle') is-invalid @enderror"
                                           placeholder="{{ __('Titel des Streams') }}">
                                    @error('newTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-sm-9">
                                        <label class="form-label form-label-sm">URL</label>
                                        <input type="url" wire:model="newUrl"
                                               class="form-control form-control-sm @error('newUrl') is-invalid @enderror"
                                               placeholder="https://...">
                                        @error('newUrl') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="col-sm-3">
                                        <label class="form-label form-label-sm">{{ __('Dauer (Sek.)') }}</label>
                                        <input type="number" wire:model="newDuration"
                                               class="form-control form-control-sm"
                                               placeholder="0" min="1">
                                    </div>
                                </div>

                            {{-- Aus Bibliothek wählen --}}
                            @elseif($addMode === 'library')
                                <div class="mb-2">
                                    <div class="input-group input-group-sm mb-2">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="text" wire:model.live.debounce.250ms="librarySearch"
                                               class="form-control" placeholder="{{ __('Suche in Bibliothek...') }}">
                                    </div>

                                    @error('selectedMediaFileId')
                                        <div class="alert alert-danger py-1 px-2 small mb-2">{{ __('Bitte eine Datei auswählen.') }}</div>
                                    @enderror

                                    @if($libraryFiles->isEmpty())
                                        <p class="text-muted small mb-0">
                                            {{ __('Keine :type-Dateien in der Bibliothek.', ['type' => $newType === 'music' ? __('Musik') : __('Jingle')]) }}
                                            <a href="{{ route('media.index') }}" wire:navigate>{{ __('Jetzt hochladen') }}</a>
                                        </p>
                                    @else
                                        <div class="list-group" style="max-height:220px;overflow-y:auto">
                                            @foreach($libraryFiles as $file)
                                                <button type="button"
                                                        wire:click="$set('selectedMediaFileId', {{ $file->id }})"
                                                        class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-1 px-2 small
                                                               {{ $selectedMediaFileId === $file->id ? 'active' : '' }}">
                                                    <i class="bi bi-file-earmark-music"></i>
                                                    <span class="text-truncate flex-grow-1">#{{$file->id}}: {{ $file->title }}</span>
                                                    @if($file->durationFormatted())
                                                        <span class="text-nowrap opacity-75">{{ $file->durationFormatted() }}</span>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                            {{-- Neu hochladen --}}
                            @else
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">{{ __('Titel') }}</label>
                                    <input type="text" wire:model="newTitle"
                                           class="form-control form-control-sm @error('newTitle') is-invalid @enderror"
                                           placeholder="{{ __('Titel des Tracks') }}">
                                    @error('newTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label form-label-sm">{{ __('MP3-Datei') }}</label>
                                    <input type="file" wire:model="newFile" accept=".mp3,.m4a,.ogg,.wav,.flac"
                                           class="form-control form-control-sm @error('newFile') is-invalid @enderror">
                                    @error('newFile') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                    <div wire:loading wire:target="newFile" class="form-text text-muted">
                                        <i class="bi bi-arrow-repeat me-1"></i>{{ __('Wird hochgeladen...') }}
                                    </div>
                                </div>
                            @endif

                            {{-- Relativer Zeitstempel (nicht bei fill/random/adbreak) --}}
                            @if(! in_array($newType, ['fill', 'random', 'adbreak']))
                                <div class="mb-2" style="max-width:180px">
                                    <label class="form-label form-label-sm">
                                        <i class="bi bi-stopwatch me-1"></i>{{ __('Zeitstempel') }}
                                        <span class="text-muted fw-normal">{{ __('(optional, MM:SS)') }}</span>
                                    </label>
                                    <input type="text" wire:model="newRelativeOffset"
                                           class="form-control form-control-sm"
                                           placeholder="z.B. 15:00">
                                    <div class="form-text">{{ __('Zeitpunkt ab Playlist-Start') }}</div>
                                </div>
                            @endif

                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" class="btn btn-sm btn-success"
                                        wire:loading.attr="disabled" wire:target="addItem,newFile">
                                    <span wire:loading wire:target="addItem" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ __('Hinzufügen') }}
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        wire:click="$set('showAddForm', false)">{{ __('Abbrechen') }}</button>
                            </div>
                        </form>
                    </div>
                @endif

                {{-- Element-Liste mit Drag & Drop --}}
                <div class="list-group list-group-flush"
                     id="sortable-items"
                     x-data
                     x-init="
                         Sortable.create($el, {
                             handle: '.drag-handle',
                             animation: 150,
                             ghostClass: 'opacity-50',
                             onEnd(evt) {
                                 const ids = [...$el.children]
                                     .map(el => parseInt(el.dataset.itemId))
                                     .filter(id => !isNaN(id));
                                 $wire.reorder(ids);
                             }
                         })
                     ">
                    @forelse($items as $item)
                        <div wire:key="item-{{ $item->id }}" data-item-id="{{ $item->id }}">
                            <div class="list-group-item d-flex align-items-center gap-3 py-2">

                                {{-- Drag-Handle --}}
                                <i class="bi bi-grip-vertical text-muted drag-handle" style="cursor:grab"></i>

                                {{-- Typ-Badge --}}
                                @php
                                    $badgeClass = match($item->type) {
                                        'music'        => 'bg-primary',
                                        'jingle'       => 'bg-warning text-dark',
                                        'url'          => 'bg-info text-dark',
                                        'external'     => 'bg-info text-dark',
                                        'fill'         => 'bg-success',
                                        'random'       => 'bg-dark',
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
                                        @elseif($item->type === 'external')
                                            <span class="fst-italic"><i class="bi bi-rss me-1"></i>{{ $item->externalSource?->name ?? __('externe Quelle') }}</span>
                                            @if($item->durationFormatted())
                                                <span><i class="bi bi-clock me-1"></i>{{ $item->durationFormatted() }}</span>
                                            @endif
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
                                            @if($item->durationFormatted())
                                                <span><i class="bi bi-clock me-1"></i>{{ $item->durationFormatted() }}</span>
                                            @endif
                                            @if($item->relative_offset_seconds !== null)
                                                <span class="text-warning-emphasis">
                                                    <i class="bi bi-stopwatch me-1"></i>{{ $this->formatOffset($item->relative_offset_seconds) }}
                                                </span>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                {{-- Bearbeiten (nicht bei adbreak/news/weather – nichts zu konfigurieren) --}}
                                @if(! in_array($item->type, ['adbreak', 'news', 'weather', 'news_weather']))
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="startEditingItem({{ $item->id }})"
                                            title="{{ __('Bearbeiten') }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                @endif

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
                                                   placeholder="{{ __('leer = bis Stunden-Ende') }}" min="60" max="7200">
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
                        <div class="list-group-item text-center text-muted py-4">
                            <i class="bi bi-music-note-beamed me-1"></i>{{ __('Noch keine Elemente. Füge das erste hinzu!') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
