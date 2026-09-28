{{-- One external source. Expects $source and $groupKey (null outside a group) plus the list variables of the index view. --}}
<div class="list-group-item d-flex align-items-center gap-3 py-2 {{ $groupKey ? 'ps-4' : '' }} {{ $highlightId === $source->id ? 'list-row-highlight' : '' }}"
     id="source-row-{{ $source->id }}"
     @if($groupKey) data-group="{{ $groupKey }}" @endif
     wire:key="src-{{ $source->id }}">
    <span class="badge bg-{{ $kindBadge[$source->kind] ?? 'info text-dark' }} text-nowrap flex-shrink-0" style="min-width:110px">
        {{ $kindLabels[$source->kind] ?? $source->kind }}
    </span>

    <div class="flex-grow-1 overflow-hidden">
        <div class="small text-truncate">
            {{-- The name opens the dialog, like in the media library --}}
            <button type="button" class="btn btn-link p-0 align-baseline text-decoration-none fw-medium text-truncate mw-100"
                    wire:click="startEdit({{ $source->id }})"
                    @if($firstHit) data-first-hit @endif>{{ $source->name }}</button>
            @if($source->broadcast_title && ! $groupKey)
                <span class="text-muted fw-normal ms-1" style="font-size:.75rem">
                    <i class="bi bi-broadcast-pin me-1"></i>{{ __('on air: :title', ['title' => $source->broadcast_title]) }}
                </span>
            @endif
        </div>
        <div class="text-muted text-truncate" style="font-size:.75rem">
            @if($source->kind === 'url')
                <i class="bi bi-link-45deg me-1"></i>{{ $source->url }}
                @if($source->url_username)
                    <span class="ms-1" title="{{ __('Login stored') }}"><i class="bi bi-shield-lock me-1"></i>{{ $source->url_username }}</span>
                @endif
            @elseif($source->kind === 'syndication')
                @unless($groupKey)
                    <i class="bi bi-broadcast me-1"></i>{{ __('S4R-Sendung #:id', ['id' => $source->syndication_sendung_id]) }} · {{ $source->syndication_variant === 'lfm' ? 'laut.fm' : __('Standard') }} ·
                @endunless
                @if($source->syndication_filename)<i class="bi bi-file-earmark-music me-1"></i>{{ $source->syndication_filename }}@endif
            @else
                <i class="bi bi-link-45deg me-1"></i>{{ __('laut.fm (aus Ausgang)') }}
            @endif
            @if($source->expectedDurationFormatted())
                <span class="ms-2"><i class="bi bi-clock me-1"></i>{{ $source->expectedDurationFormatted() }}</span>
            @elseif($source->kind === 'syndication')
                <span class="ms-2 text-warning-emphasis"><i class="bi bi-clock-history me-1"></i>{{ __('Länge unbekannt') }}</span>
            @endif
            <span class="ms-2" title="{{ __('Wie früh vor Ausspielung der Inhalt geholt und analysiert wird.') }}"><i class="bi bi-stopwatch me-1"></i>{{ __('Vorlauf :n s', ['n' => $source->prefetch_lead_seconds]) }}</span>
            @if($source->freshness_seconds > 0)
                <span class="ms-2"><i class="bi bi-arrow-repeat me-1"></i>{{ __('Frische :n s', ['n' => $source->freshness_seconds]) }}</span>
            @endif
            @if($source->normalize)
                <i class="bi bi-soundwave ms-2 text-success" title="{{ __('Normalisierung') }}"></i>
            @endif
            @if($source->trim_leading_silence)
                <i class="bi bi-scissors ms-1 text-success" title="{{ __('Stille-Trim') }}"></i>
            @endif
            @if($source->fade_in)
                <i class="bi bi-arrow-up-right ms-1 text-success" title="{{ __('Fade-in') }}"></i>
            @endif
        </div>

        {{-- Status of the last fetch --}}
        @if($source->last_error)
            <div class="text-danger text-truncate" style="font-size:.72rem" title="{{ $source->last_error }}">
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

        {{-- Prepared copies on disk --}}
        @if($showingFilesForId === $source->id)
            <div class="mt-2 border-start border-2 ps-2" style="font-size:.72rem">
                @forelse($preparedFiles as $file)
                    <div class="d-flex gap-2 align-items-baseline">
                        @if($file['exists'])
                            <i class="bi bi-file-earmark-music text-success"></i>
                        @else
                            <i class="bi bi-file-earmark-x text-danger" title="{{ __('File is gone') }}"></i>
                        @endif
                        <code class="text-body">{{ $file['path'] }}</code>
                        <span class="text-muted text-nowrap">
                            @if($file['bytes'] !== null)
                                {{ number_format($file['bytes'] / 1048576, 1) }} MB
                            @else
                                {{ __('gone') }}
                            @endif
                            @if($file['broadcast_at'])
                                · {{ __('on air :time', ['time' => $file['broadcast_at']]) }}
                            @endif
                            @if($file['prepared_at'])
                                · {{ __('fetched :time', ['time' => $file['prepared_at']]) }}
                            @endif
                        </span>
                    </div>
                @empty
                    <span class="text-muted">{{ __('No prepared copy on disk right now.') }}</span>
                @endforelse
                <div class="text-muted mt-1">
                    {{ __('Copies are deleted an hour after they aired.') }}
                </div>
            </div>
        @endif
    </div>

    <span class="text-muted-sm text-nowrap text-end flex-shrink-0" style="width:5.5rem">
        @if($source->playlist_items_count > 0)
            <i class="bi bi-collection me-1"></i>{{ $source->playlist_items_count }}×
        @else
            <span class="text-muted">{{ __('Unbenutzt') }}</span>
        @endif
    </span>

    <div class="d-flex gap-1 flex-shrink-0">
        <button class="btn btn-sm btn-outline-secondary" wire:click="startEdit({{ $source->id }})" title="{{ __('Bearbeiten') }}">
            <i class="bi bi-pencil"></i>
        </button>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('More actions') }}">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm small">
                @if($source->kind === 'syndication')
                    <li>
                        <button class="dropdown-item" wire:click="refreshSyndicationDuration({{ $source->id }})">
                            <i class="bi bi-arrow-clockwise me-2"></i>{{ __('Länge von S4R aktualisieren') }}
                        </button>
                    </li>
                @else
                    <li>
                        <button class="dropdown-item" wire:click="startDuplicate({{ $source->id }})">
                            <i class="bi bi-copy me-2"></i>{{ __('Duplicate') }}
                        </button>
                    </li>
                @endif
                <li>
                    <button class="dropdown-item" wire:click="togglePreparedFiles({{ $source->id }})">
                        <i class="bi bi-hdd me-2"></i>{{ $showingFilesForId === $source->id ? __('Hide the prepared copies') : __('Show the prepared copies on disk') }}
                    </button>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <button class="dropdown-item text-danger"
                            @click="$dispatch('confirm-dialog', { message: @js($source->playlist_items_count > 0
                                ? __('Diese Quelle wird in :n Playlist-Element(en) verwendet. Trotzdem löschen?', ['n' => $source->playlist_items_count])
                                : __('Quelle löschen?')), confirmText: @js(__('Löschen')), onConfirm: () => $wire.delete({{ $source->id }}) })">
                        <i class="bi bi-trash me-2"></i>{{ __('Löschen') }}
                    </button>
                </li>
            </ul>
        </div>
    </div>
</div>
