<div>
    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-rss me-2 text-primary"></i>{{ __('Externe Quellen') }}
        </h4>
        @unless($showForm)
            <button class="btn btn-primary btn-sm" wire:click="startCreate">
                <i class="bi bi-plus-lg me-1"></i>{{ __('Quelle hinzufügen') }}
            </button>
        @endunless
    </div>

    <p class="text-muted small">
        {{ __('Dynamische HTTP-Inhalte (Syndication, laut.fm-Nachrichten/Wetter). Sie werden kurz vor Ausspielung geholt, geprüft und – falls rechtzeitig gemessen – normalisiert. In Playlisten als Element einbindbar.') }}
    </p>

    {{-- Syndications4Radio-Verbindung --}}
    <div class="card mb-4 border-primary-subtle">
        <div class="card-body py-3">
            @if($station->hasSyndicationConnection())
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>{{ __('Mit Syndications4Radio verbunden') }}</span>
                    <span class="text-muted small flex-grow-1">{{ __('Gebuchte Syndications direkt importieren.') }}</span>
                    <button class="btn btn-primary btn-sm" wire:click="startImport" wire:loading.attr="disabled" wire:target="startImport">
                        <span wire:loading wire:target="startImport" class="spinner-border spinner-border-sm me-1"></span>
                        <i wire:loading.remove wire:target="startImport" class="bi bi-cloud-download me-1"></i>{{ __('Syndications importieren') }}
                    </button>
                    <button class="btn btn-outline-secondary btn-sm"
                            @click="$dispatch('confirm-dialog', { message: @js(__('Verbindung zu Syndications4Radio trennen? Bereits importierte Quellen bleiben bestehen, können aber keine neuen Dateien mehr abrufen.')), confirmText: @js(__('Trennen')), confirmClass: 'btn-warning', onConfirm: () => $wire.disconnectS4r() })">
                        <i class="bi bi-x-circle me-1"></i>{{ __('Trennen') }}
                    </button>
                </div>
            @else
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-broadcast text-primary fs-5"></i>
                    <span class="fw-medium">{{ __('Syndications4Radio verbinden') }}</span>
                </div>
                <p class="text-muted small mb-2">
                    {{ __('Erzeuge im Radiomanager bei S4R einen Partner-API-Token und füge ihn hier ein. Danach kannst du deine gebuchten Syndications importieren.') }}
                </p>
                <form wire:submit="connectS4r" class="d-flex flex-wrap gap-2" style="max-width:560px">
                    <input type="text" wire:model="s4rTokenInput"
                           class="form-control form-control-sm @error('s4rTokenInput') is-invalid @enderror"
                           style="flex:1 1 280px" placeholder="{{ __('Partner-API-Token') }}">
                    <button type="submit" class="btn btn-primary btn-sm text-nowrap">
                        <i class="bi bi-link-45deg me-1"></i>{{ __('Verbinden') }}
                    </button>
                    @error('s4rTokenInput') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </form>
            @endif
        </div>
    </div>

    {{-- Import-Assistent (mehrstufig) --}}
    @if($showImport)
        <div class="card mb-4">
            <div class="card-header fw-medium d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-cloud-download me-1"></i>{{ __('Syndications importieren') }}
                    <span class="badge bg-secondary ms-1">{{ __('Schritt :n/2', ['n' => $importStep]) }}</span>
                </span>
                <button type="button" class="btn-close btn-sm" wire:click="cancelImport"></button>
            </div>
            <div class="card-body">
                @if($importError)
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ $importError }}
                    </div>
                @endif

                @if($importStep === 1)
                    @if(empty($importShows) && ! $importError)
                        <p class="text-muted small mb-0">{{ __('Keine genehmigten Sendungen mit abrufbaren Dateien gefunden.') }}</p>
                    @else
                        <p class="small fw-medium mb-2">{{ __('Welche Sendung möchtest du importieren?') }}</p>
                        <div class="list-group">
                            @foreach($importShows as $show)
                                <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-3"
                                        wire:key="show-{{ $show['id'] }}"
                                        wire:click="selectImportShow({{ $show['id'] }})">
                                    <i class="bi bi-broadcast text-primary"></i>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div class="fw-medium small text-truncate">{{ $show['name'] }}</div>
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
                    @endif
                @elseif($importStep === 2 && $importSelectedShow)
                    <p class="small mb-1">
                        {{ __('Welche Datei-Variante von') }} <strong>{{ $importSelectedShow['name'] }}</strong> {{ __('möchtest du importieren?') }}
                    </p>
                    <p class="text-muted mb-3" style="font-size:.78rem">
                        <i class="bi bi-info-circle me-1"></i>{{ __('Enthält die Sendung mehrere Dateien, wird pro Datei eine eigene Quelle angelegt.') }}
                    </p>
                    <div class="d-flex flex-column gap-2 mb-3" style="max-width:480px">
                        @foreach(($importSelectedShow['available_variants'] ?? []) as $variant)
                            <label class="list-group-item d-flex align-items-center gap-2 border rounded">
                                <input class="form-check-input mt-0" type="radio" wire:model="importVariant" value="{{ $variant }}">
                                <span class="fw-medium">{{ $variant === 'lfm' ? __('laut.fm-Dateien') : __('Standard-Dateien') }}</span>
                                <span class="text-muted small">({{ $variant }})</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm" wire:click="backToShowList">
                            <i class="bi bi-arrow-left me-1"></i>{{ __('Zurück') }}
                        </button>
                        <button class="btn btn-primary btn-sm" wire:click="importShow">
                            <i class="bi bi-plus-lg me-1"></i>{{ __('Importieren') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Formular --}}
    @if($showForm)
        <div class="card mb-4">
            <div class="card-header fw-medium d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-{{ $editingId ? 'pencil' : 'plus-lg' }} me-1"></i>
                    {{ $editingId ? __('Quelle bearbeiten') : __('Neue Quelle') }}
                </span>
                <button type="button" class="btn-close btn-sm" wire:click="cancel"></button>
            </div>
            <div class="card-body">
                <form wire:submit="save">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-medium">{{ __('Name') }}</label>
                            <input type="text" wire:model="name"
                                   class="form-control form-control-sm @error('name') is-invalid @enderror"
                                   placeholder="{{ __('z.B. Morgenshow-Syndication') }}">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-medium">{{ __('Art') }}</label>
                            <select wire:model.live="kind" class="form-select form-select-sm @error('kind') is-invalid @enderror"
                                    @disabled($kind === 'syndication')>
                                <option value="url">{{ __('HTTP-URL (Syndication)') }}</option>
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
                                <label class="form-label small fw-medium">{{ __('URL') }}</label>
                                <input type="url" wire:model="url"
                                       class="form-control form-control-sm @error('url') is-invalid @enderror"
                                       placeholder="https://...">
                                @error('url') <div class="invalid-feedback">{{ $message }}</div> @enderror
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

                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-medium">{{ __('Erwartete Dauer (s)') }}</label>
                            <input type="number" wire:model="expectedDuration" min="1"
                                   class="form-control form-control-sm @error('expectedDuration') is-invalid @enderror"
                                   placeholder="{{ __('optional') }}">
                            @error('expectedDuration') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-medium">
                                {{ __('Vorlauf (s)') }}
                                <i class="bi bi-question-circle text-muted" title="{{ __('Wie früh vor Ausspielung der Inhalt geholt und analysiert wird.') }}"></i>
                            </label>
                            <input type="number" wire:model="prefetchLead" min="0"
                                   class="form-control form-control-sm @error('prefetchLead') is-invalid @enderror">
                            @error('prefetchLead') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-medium">
                                {{ __('Frische (s)') }}
                                <i class="bi bi-question-circle text-muted" title="{{ __('Wie lange eine geholte Kopie wiederverwendet werden darf. 0 = immer frisch holen (z.B. Nachrichten).') }}"></i>
                            </label>
                            <input type="number" wire:model="freshness" min="0"
                                   class="form-control form-control-sm @error('freshness') is-invalid @enderror">
                            @error('freshness') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="normalize" id="normalize">
                                <label class="form-check-label small" for="normalize">{{ __('Normalisieren') }}</label>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="trimLeadingSilence" id="trimLeadingSilence">
                                <label class="form-check-label small" for="trimLeadingSilence">
                                    {{ __('Stille am Anfang entfernen') }}
                                    <i class="bi bi-question-circle text-muted" title="{{ __('Schneidet beim Vorbereiten führende Stille weg (z.B. das Loch vor dem Nachrichten-Intro).') }}"></i>
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" wire:model="fadeIn" id="fadeIn">
                                <label class="form-check-label small" for="fadeIn">
                                    {{ __('Sanft einblenden (Fade-in)') }}
                                    <i class="bi bi-question-circle text-muted" title="{{ __('Blendet das Element beim Ausspielen weich ein statt hart einzusetzen.') }}"></i>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-floppy me-1"></i>{{ __('Speichern') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="cancel">
                            {{ __('Abbrechen') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Liste --}}
    @if($sources->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-rss display-4 text-muted"></i>
            <p class="mt-3 text-muted">{{ __('Noch keine externen Quellen angelegt.') }}</p>
        </div>
    @else
        @php
            $kindLabels = ['url' => __('HTTP-URL'), 'news' => __('Nachrichten'), 'weather' => __('Wetter'), 'news_weather' => __('Nachrichten + Wetter'), 'syndication' => __('Syndication')];
            $kindBadge = ['url' => 'primary', 'syndication' => 'success'];
        @endphp
        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($sources as $source)
                    <div class="list-group-item d-flex align-items-center gap-3 py-2" wire:key="src-{{ $source->id }}">
                        <span class="badge bg-{{ $kindBadge[$source->kind] ?? 'info text-dark' }} text-nowrap" style="min-width:120px">
                            {{ $kindLabels[$source->kind] ?? $source->kind }}
                        </span>

                        <div class="flex-grow-1 overflow-hidden">
                            <div class="fw-medium small text-truncate">{{ $source->name }}</div>
                            <div class="text-muted" style="font-size:.75rem">
                                @if($source->kind === 'url')
                                    <i class="bi bi-link-45deg me-1"></i><span class="text-truncate">{{ $source->url }}</span>
                                @elseif($source->kind === 'syndication')
                                    <i class="bi bi-broadcast me-1"></i>{{ __('S4R-Sendung #:id', ['id' => $source->syndication_sendung_id]) }} · {{ $source->syndication_variant === 'lfm' ? 'laut.fm' : __('Standard') }}@if($source->syndication_filename) · <span class="text-truncate">{{ $source->syndication_filename }}</span>@endif @if($source->expectedDurationFormatted())<span class="ms-1"><i class="bi bi-clock me-1"></i>{{ $source->expectedDurationFormatted() }}</span>@else<span class="ms-1 text-warning"><i class="bi bi-clock-history me-1"></i>{{ __('Länge unbekannt') }}</span>@endif
                                @else
                                    <i class="bi bi-link-45deg me-1"></i>{{ __('laut.fm (aus Ausgang)') }}
                                @endif
                                <span class="ms-2"><i class="bi bi-stopwatch me-1"></i>{{ __('Vorlauf :n s', ['n' => $source->prefetch_lead_seconds]) }}</span>
                                <span class="ms-2"><i class="bi bi-arrow-repeat me-1"></i>{{ $source->freshness_seconds > 0 ? __('Frische :n s', ['n' => $source->freshness_seconds]) : __('immer frisch') }}</span>
                                @if($source->normalize)
                                    <span class="ms-2 text-success"><i class="bi bi-soundwave me-1"></i>{{ __('Normalisierung') }}</span>
                                @endif
                                @if($source->trim_leading_silence)
                                    <span class="ms-2 text-success"><i class="bi bi-scissors me-1"></i>{{ __('Stille-Trim') }}</span>
                                @endif
                                @if($source->fade_in)
                                    <span class="ms-2 text-success"><i class="bi bi-arrow-up-right me-1"></i>{{ __('Fade-in') }}</span>
                                @endif
                            </div>
                            {{-- Betriebs-Status des letzten Abrufs --}}
                            @if($source->last_error)
                                <div class="text-danger" style="font-size:.72rem">
                                    <i class="bi bi-exclamation-triangle me-1"></i>{{ Str::limit($source->last_error, 120) }}
                                    @if($source->last_fetched_at)
                                        <span class="text-muted">({{ $source->last_fetched_at->diffForHumans() }})</span>
                                    @endif
                                </div>
                            @elseif($source->last_fetched_at)
                                <div class="text-muted" style="font-size:.72rem">
                                    <i class="bi bi-check-circle me-1 text-success"></i>{{ __('Zuletzt geholt :time', ['time' => $source->last_fetched_at->diffForHumans()]) }}
                                    @if($source->last_loudness_lufs !== null)
                                        <span class="ms-1">· {{ number_format($source->last_loudness_lufs, 1) }} LUFS</span>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <span class="text-muted-sm text-nowrap">
                            @if($source->playlist_items_count > 0)
                                <i class="bi bi-collection me-1"></i>{{ $source->playlist_items_count }}×
                            @else
                                <span class="text-muted">{{ __('Unbenutzt') }}</span>
                            @endif
                        </span>

                        @if($source->kind === 'syndication')
                            <button class="btn btn-sm btn-outline-secondary"
                                    wire:click="refreshSyndicationDuration({{ $source->id }})"
                                    wire:loading.attr="disabled" wire:target="refreshSyndicationDuration"
                                    title="{{ __('Länge von S4R aktualisieren') }}">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        @endif
                        <button class="btn btn-sm btn-outline-secondary" wire:click="startEdit({{ $source->id }})" title="{{ __('Bearbeiten') }}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                                @click="$dispatch('confirm-dialog', { message: @js($source->playlist_items_count > 0
                                    ? __('Diese Quelle wird in :n Playlist-Element(en) verwendet. Trotzdem löschen?', ['n' => $source->playlist_items_count])
                                    : __('Quelle löschen?')), confirmText: @js(__('Löschen')), onConfirm: () => $wire.delete({{ $source->id }}) })"
                                title="{{ __('Löschen') }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
