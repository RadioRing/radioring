<div x-data="mediaPreview">
    {{-- Geteiltes Audio-Element zum Vorhören (es spielt immer nur ein Titel) --}}
    <audio x-ref="audio" preload="none" class="d-none"
           @ended="playingId = null"
           x-on:error="errorId = playingId; loadingId = null; playingId = null"></audio>

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-collection-play me-2 text-primary"></i>{{ __('Media library') }}
        </h4>
        <div class="d-flex gap-2">
            @if($this->mayWrite)
                <button class="btn btn-outline-secondary btn-sm" wire:click="$toggle('showTagManager')">
                    <i class="bi bi-tags me-1"></i>{{ __('Tags') }}
                    @if($tags->isNotEmpty())
                        <span class="badge bg-secondary ms-1">{{ $tags->count() }}</span>
                    @endif
                </button>
                <button class="btn btn-primary btn-sm" wire:click="$set('showUploadForm', true)">
                    <i class="bi bi-cloud-upload me-1"></i>{{ __('Upload') }}
                </button>
            @endif
        </div>
    </div>

    {{-- The library belongs to the tenant, so every station of the tenant sees it. --}}
    @unless($this->mayWrite)
        <div class="alert alert-secondary d-flex align-items-center gap-2 py-2">
            <i class="bi bi-eye fs-5"></i>
            <div>{{ __('You can use this library in your playlists, but not change it.') }}</div>
        </div>
    @endunless

    {{-- Tag-Manager --}}
    @if($showTagManager && $this->mayWrite)
        <div class="card mb-4">
            <div class="card-header fw-medium d-flex align-items-center justify-content-between">
                <span><i class="bi bi-tags me-1"></i>{{ __('Tags verwalten') }}</span>
                <button type="button" class="btn-close btn-sm" wire:click="$set('showTagManager', false)"></button>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @forelse($tags as $tag)
                        <span class="badge bg-secondary d-flex align-items-center gap-1" style="font-size:.85rem">
                            {{ $tag->name }}
                            <button type="button" class="btn-close btn-close-white ms-1"
                                    style="font-size:.6rem"
                                    @click="$dispatch('confirm-dialog', { message: @js(__('Tag \':name\' löschen? Er wird von allen Dateien entfernt.', ['name' => $tag->name])), confirmText: @js(__('Löschen')), onConfirm: () => $wire.deleteTag({{ $tag->id }}) })"></button>
                        </span>
                    @empty
                        <p class="text-muted small mb-0">{{ __('Noch keine Tags angelegt.') }}</p>
                    @endforelse
                </div>
                <form wire:submit="createTag" class="d-flex gap-2" style="max-width:320px">
                    <input type="text" wire:model="newTagName"
                           class="form-control form-control-sm @error('newTagName') is-invalid @enderror"
                           placeholder="{{ __('Neuer Tag, z.B. 80er') }}">
                    @error('newTagName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <button type="submit" class="btn btn-sm btn-secondary text-nowrap">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Upload-Bereich --}}
    @if($showUploadForm && $this->mayWrite)
        <div class="card mb-4" x-data="chunkUploader">
            <div class="card-header fw-medium d-flex align-items-center justify-content-between">
                <span><i class="bi bi-cloud-upload me-1"></i>{{ __('Dateien hochladen') }}</span>
                <button type="button" class="btn-close btn-sm" wire:click="cancelUpload"></button>
            </div>
            <div class="card-body">

                {{-- Drop-Zone --}}
                <div class="border rounded-3 p-4 text-center mb-3"
                     :class="dragging ? 'border-primary bg-primary bg-opacity-10' : ''"
                     style="border-style: dashed !important; border-color: #6c757d; cursor: pointer; transition: all .15s"
                     @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="onDrop($event)"
                     @click="$refs.fileInput.click()">
                    <i class="bi bi-cloud-upload display-5 text-muted mb-2 d-block"></i>
                    <p class="mb-1 fw-medium">{{ __('Dateien hier ablegen') }}</p>
                    <p class="text-muted small mb-0">{{ __('oder klicken zum Auswählen') }} &ndash; MP3, M4A, OGG, WAV, FLAC</p>
                    <input type="file" x-ref="fileInput"
                           accept=".mp3,.m4a,.ogg,.wav,.flac"
                           multiple class="d-none"
                           @change="onFileInput($event.target.files)">
                </div>

                {{-- Aktive Uploads (Fortschritt) --}}
                <template x-if="queue.length > 0">
                    <div class="mb-3">
                        {{-- Gesamtstatus --}}
                        <div class="d-flex align-items-center justify-content-between mb-2 small fw-medium">
                            <span>
                                <span x-text="queue.filter(q => q.status === 'done').length"></span><span>/</span><span x-text="queue.length"></span>
                                {{ __('hochgeladen') }}
                            </span>
                            <template x-if="queue.every(q => q.status === 'done' || q.status === 'error')">
                                <span class="text-success"><i class="bi bi-check2-all me-1"></i>{{ __('fertig') }}</span>
                            </template>
                            <template x-if="queue.some(q => q.status === 'uploading' || q.status === 'queued')">
                                <span class="text-muted"><span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem"></span>{{ __('lädt hoch...') }}</span>
                            </template>
                        </div>

                        <template x-for="item in queue" :key="item.id">
                            <div class="d-flex align-items-center gap-2 py-1 small">
                                <i class="bi bi-file-earmark-music text-muted flex-shrink-0"></i>
                                <span class="text-truncate flex-grow-1" style="max-width:260px" x-text="item.name"></span>

                                <template x-if="item.status === 'queued'">
                                    <span class="text-muted flex-shrink-0"><i class="bi bi-hourglass-split me-1"></i>{{ __('wartet') }}</span>
                                </template>
                                <template x-if="item.status === 'uploading'">
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <div class="progress" style="width:100px;height:6px">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated"
                                                 :style="`width:${item.progress}%`"></div>
                                        </div>
                                        <span class="text-muted" x-text="item.progress + '%'" style="min-width:32px"></span>
                                    </div>
                                </template>
                                <template x-if="item.status === 'done'">
                                    <i class="bi bi-check-circle-fill text-success flex-shrink-0"></i>
                                </template>
                                <template x-if="item.status === 'error'">
                                    <span class="text-danger flex-shrink-0 small" x-text="item.error"></span>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Warteschlange: fertig hochgeladen, noch nicht gespeichert --}}
                @if(! empty($pendingUploads))
                    <div class="mb-3">
                        <div class="fw-medium small mb-2">
                            {{ __(':n Datei(en) bereit zum Speichern', ['n' => count($pendingUploads)]) }}
                        </div>
                        <div class="list-group">
                            @foreach($pendingUploads as $i => $upload)
                                <div wire:key="pending-{{ $i }}" class="list-group-item px-3 py-2">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="flex-shrink-0 pt-1">
                                            <i class="bi bi-file-earmark-music text-muted fs-5"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="text-muted mb-2" style="font-size:.75rem">
                                                {{ $upload['clientName'] }}
                                                @if($upload['duration'])
                                                    @php $m = intdiv($upload['duration'], 60); $s = $upload['duration'] % 60; @endphp
                                                    <span class="ms-2 badge bg-light text-dark border">
                                                        <i class="bi bi-clock me-1"></i>{{ sprintf('%d:%02d', $m, $s) }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-12 col-sm-4">
                                                    <input type="text"
                                                           wire:model="pendingUploads.{{ $i }}.title"
                                                           class="form-control form-control-sm @error("pendingUploads.{$i}.title") is-invalid @enderror"
                                                           placeholder="{{ __('Titel') }}">
                                                    @error("pendingUploads.{$i}.title")
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="col-6 col-sm-3">
                                                    <input type="text"
                                                           wire:model="pendingUploads.{{ $i }}.artist"
                                                           class="form-control form-control-sm @error("pendingUploads.{$i}.artist") is-invalid @enderror"
                                                           placeholder="{{ __('Interpret') }}">
                                                    @error("pendingUploads.{$i}.artist")
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="col-6 col-sm-3">
                                                    <input type="text"
                                                           wire:model="pendingUploads.{{ $i }}.album"
                                                           class="form-control form-control-sm @error("pendingUploads.{$i}.album") is-invalid @enderror"
                                                           placeholder="{{ __('Album') }}">
                                                    @error("pendingUploads.{$i}.album")
                                                        <div class="invalid-feedback">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="col-sm-2">
                                                    <select wire:model="pendingUploads.{{ $i }}.type"
                                                            class="form-select form-select-sm">
                                                        <option value="music">{{ __('Musik') }}</option>
                                                        <option value="jingle">{{ __('Jingle') }}</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger flex-shrink-0"
                                                wire:click="removePending({{ $i }})"
                                                title="{{ __('Entfernen') }}">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary btn-sm"
                                wire:click="save"
                                wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading wire:target="save" class="spinner-border spinner-border-sm me-1"></span>
                            <i wire:loading.remove wire:target="save" class="bi bi-floppy me-1"></i>
                            {{ __('Alle speichern') }}
                            <span class="badge bg-white text-primary ms-1">{{ count($pendingUploads) }}</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                wire:click="cancelUpload">{{ __('Abbrechen') }}</button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Duplicate hint --}}
    @if(count($duplicateIds) > 0)
            <div class="alert alert-warning d-flex align-items-center flex-wrap gap-2 py-2">
                <i class="bi bi-files fs-5"></i>
                <div class="flex-grow-1">
                    {{ __(':n Datei(en) haben denselben Interpret und Titel wie eine andere – vermutlich doppelt hochgeladen.', ['n' => count($duplicateIds)]) }}
                </div>
                @if($filterDuplicates)
                    <button class="btn btn-sm btn-outline-secondary text-nowrap" wire:click="$set('filterDuplicates', false)">
                        <i class="bi bi-x-lg me-1"></i>{{ __('Filter aufheben') }}
                    </button>
                @else
                    <button class="btn btn-sm btn-warning text-nowrap" wire:click="$set('filterDuplicates', true)">
                        <i class="bi bi-funnel me-1"></i>{{ __('Nur Duplikate zeigen') }}
                    </button>
                @endif
        </div>
    @endif

    {{-- Filter & search --}}
    <div class="row g-2 mb-3">
        <div class="col-sm-4 col-md-3">
            <select wire:model.live="filterType" class="form-select form-select-sm">
                <option value="">{{ __('Alle Typen') }}</option>
                <option value="music">{{ __('Musik') }}</option>
                <option value="jingle">{{ __('Jingle') }}</option>
            </select>
        </div>
        <div class="col-sm-4 col-md-3">
            <select wire:model.live="filterTagId" class="form-select form-select-sm">
                <option value="">{{ __('All tags') }}</option>
                <option value="none">{{ __('Untagged') }}</option>
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-4 col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" wire:model.live.debounce.300ms="search"
                       class="form-control" placeholder="{{ __('Suche...') }}">
            </div>
        </div>
    </div>

    {{-- Dateiliste --}}
    @if($files->isEmpty())
        <div class="text-center py-5">
            <i class="bi bi-music-note-beamed display-4 text-muted"></i>
            <p class="mt-3 text-muted">{{ __('Noch keine Dateien in der Bibliothek.') }}</p>
        </div>
    @else
        @php $allVisibleSelected = $files->isNotEmpty() && $files->pluck('id')->diff($selectedFileIds)->isEmpty(); @endphp

        {{-- Auswahl-Leiste --}}
        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="select-all"
                       wire:click="toggleSelectAll" @checked($allVisibleSelected)>
                <label class="form-check-label small" for="select-all">{{ __('Alle auswählen') }}</label>
            </div>

            @if(count($selectedFileIds) > 0)
                <span class="badge bg-primary">{{ __(':n selected', ['n' => count($selectedFileIds)]) }}</span>

                @if($tags->isNotEmpty() && $this->mayWrite)
                    <div class="d-flex align-items-center gap-1 ms-auto">
                        <select wire:model="bulkTagId" class="form-select form-select-sm" style="width:auto">
                            <option value="">{{ __('Tag wählen...') }}</option>
                            @foreach($tags as $tag)
                                <option value="{{ $tag->id }}">{{ $tag->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-primary text-nowrap" wire:click="bulkAddTag">
                            <i class="bi bi-tag me-1"></i>{{ __('Hinzufügen') }}
                        </button>
                        <button class="btn btn-sm btn-outline-secondary text-nowrap" wire:click="bulkRemoveTag">
                            <i class="bi bi-tag-fill me-1"></i>{{ __('Entfernen') }}
                        </button>
                        <button class="btn btn-sm btn-link text-muted text-nowrap" wire:click="clearSelection">
                            {{ __('Auswahl aufheben') }}
                        </button>
                    </div>
                @else
                    <button class="btn btn-sm btn-link text-muted text-nowrap ms-auto" wire:click="clearSelection">
                        {{ __('Auswahl aufheben') }}
                    </button>
                @endif
            @endif
        </div>
        @error('bulkTagId') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($files as $file)
                    @php
                        // One tenant library: every file listed here is usable by this station.
                        $visibleTags = $file->tags;
                    @endphp
                    <div wire:key="file-{{ $file->id }}">
                        <div class="list-group-item d-flex align-items-center gap-3 py-2">

                            {{-- Auswahl --}}
                            <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                   value="{{ $file->id }}" wire:model.live="selectedFileIds"
                                   aria-label="{{ __('Auswählen') }}">

                            {{-- Feste Breite, damit die Spalten aller Zeilen fluchten --}}
                            <span class="text-muted font-monospace flex-shrink-0 text-end"
                                  style="width:3.25rem;font-size:.75rem">#{{ $file->id }}</span>

                            {{-- Vorhören --}}
                            <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0 rounded-circle p-0"
                                    style="width:32px;height:32px"
                                    @click="toggle({{ $file->id }}, '{{ route('media.preview', $file) }}')"
                                    :title="playingId === {{ $file->id }} ? '{{ __('Pause') }}' : '{{ __('Vorhören') }}'">
                                <span x-show="loadingId === {{ $file->id }}" class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem"></span>
                                <i x-show="loadingId !== {{ $file->id }}"
                                   class="bi" :class="playingId === {{ $file->id }} ? 'bi-pause-fill' : 'bi-play-fill'"></i>
                            </button>

                            {{-- Typ-Badge --}}
                            <span class="badge bg-{{ $file->type === 'music' ? 'primary' : 'warning text-dark' }} text-nowrap"
                                  style="min-width:52px">
                                {{ $file->type === 'music' ? __('Musik') : __('Jingle') }}
                            </span>

                            {{-- Info --}}
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="text-truncate fw-medium small">
                                    <a href="{{ route('media.show', $file) }}" wire:navigate class="text-decoration-none">{{ $file->title }}</a>
                                    @if($file->artist)
                                        <span class="text-muted fw-normal">&ndash; {{ $file->artist }}</span>
                                    @endif
                                    @if(in_array($file->id, $duplicateIds))
                                        <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1" style="font-size:.65rem"
                                              title="{{ __('Gleicher Interpret und Titel wie eine andere Datei.') }}">
                                            <i class="bi bi-files"></i>{{ __('Duplikat') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-muted" style="font-size:.75rem">
                                    <i class="bi bi-file-earmark-music me-1"></i>{{ $file->filename() }}
                                    @if($file->album)
                                        <span class="ms-2"><i class="bi bi-disc me-1"></i>{{ $file->album }}</span>
                                    @endif
                                    @if($file->durationFormatted())
                                        <span class="ms-2"><i class="bi bi-clock me-1"></i>{{ $file->durationFormatted() }}</span>
                                    @endif
                                    @if($file->loudness_lufs !== null)
                                        @php $gain = $file->loudnessGainDb(); @endphp
                                        <span class="ms-2" title="{{ __('Gemessene Lautheit: :lufs LUFS, True-Peak: :tp dBTP. Angewandter Gain: :gain dB.', [
                                                'lufs' => number_format($file->loudness_lufs, 1),
                                                'tp' => $file->loudness_true_peak !== null ? number_format($file->loudness_true_peak, 1) : '–',
                                                'gain' => $gain !== null ? sprintf('%+.1f', $gain) : '0',
                                            ]) }}">
                                            <i class="bi bi-soundwave me-1"></i>{{ number_format($file->loudness_lufs, 1) }} LUFS
                                            @if($gain !== null)
                                                <span class="badge bg-light text-dark border ms-1">{{ sprintf('%+.1f dB', $gain) }}</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="ms-2 text-muted fst-italic" title="{{ __('Die Lautheit wird nach dem Upload im Hintergrund gemessen.') }}">
                                            <i class="bi bi-soundwave me-1"></i>{{ __('Lautheit ausstehend') }}
                                        </span>
                                    @endif
                                </div>
                                @if($visibleTags->isNotEmpty())
                                    <div class="mt-1 d-flex flex-wrap gap-1">
                                        @foreach($visibleTags as $tag)
                                            <span class="badge bg-secondary" style="font-size:.7rem">{{ $tag->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            {{-- Verwendung: fest eingeplant, ueber ein Fill-/Zufalls-Element
                                 erreichbar oder wirklich unbenutzt. --}}
                            <span class="text-muted-sm text-nowrap text-end flex-shrink-0" style="width:6.5rem">
                                @if($file->playlist_items_count > 0)
                                    <i class="bi bi-collection me-1"></i>{{ $file->playlist_items_count }}×
                                @elseif($this->isReachableByFill($file))
                                    <span class="text-muted" title="{{ __('Not scheduled directly, but a fill or random element can pick this file.') }}">
                                        <i class="bi bi-shuffle me-1"></i>{{ __('Via fill') }}
                                    </span>
                                @else
                                    <span class="text-muted">{{ __('Unbenutzt') }}</span>
                                @endif
                            </span>

                            @if($this->mayWrite)
                                {{-- Edit tags --}}
                                @if($tags->isNotEmpty())
                                    <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="startEditingTags({{ $file->id }})"
                                            title="{{ __('Edit tags') }}">
                                        <i class="bi bi-tags"></i>
                                    </button>
                                @endif

                                {{-- Edit metadata --}}
                                <button class="btn btn-sm btn-outline-secondary"
                                        wire:click="startEditingFile({{ $file->id }})"
                                        title="{{ __('Edit title & artist') }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            @endif

                            {{-- Details --}}
                            <a href="{{ route('media.show', $file) }}" wire:navigate
                               class="btn btn-sm btn-outline-secondary" title="{{ __('Details') }}">
                                <i class="bi bi-info-circle"></i>
                            </a>

                            @if($this->mayDelete)
                                {{-- Deleting removes the file from every station of the tenant. --}}
                                <button class="btn btn-sm btn-outline-danger"
                                        @click="$dispatch('confirm-dialog', { message: @js($file->playlist_items_count > 0
                                            ? __('This file is used in :n playlist(s) and will be removed from every station of this account. Delete anyway?', ['n' => $file->playlist_items_count])
                                            : __('Delete this file? It disappears from every station of this account.')), confirmText: @js(__('Delete')), onConfirm: () => $wire.delete({{ $file->id }}) })">
                                    <i class="bi bi-trash"></i>
                                </button>
                            @endif
                        </div>

                        {{-- Metadaten-Editor (inline) --}}
                        @if($editingFileId === $file->id)
                            <div class="list-group-item bg-light border-top-0 py-2 px-3">
                                <form wire:submit="saveFileEdit">
                                    <div class="row g-2 align-items-start">
                                        <div class="col-12 col-sm-4">
                                            <label class="form-label small fw-medium mb-1">{{ __('Titel') }}</label>
                                            <input type="text" wire:model="editTitle"
                                                   class="form-control form-control-sm @error('editTitle') is-invalid @enderror">
                                            @error('editTitle') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-6 col-sm-3">
                                            <label class="form-label small fw-medium mb-1">{{ __('Interpret') }}</label>
                                            <input type="text" wire:model="editArtist"
                                                   class="form-control form-control-sm @error('editArtist') is-invalid @enderror"
                                                   placeholder="{{ __('Unbekannt') }}">
                                            @error('editArtist') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-6 col-sm-3">
                                            <label class="form-label small fw-medium mb-1">{{ __('Album') }}</label>
                                            <input type="text" wire:model="editAlbum"
                                                   class="form-control form-control-sm @error('editAlbum') is-invalid @enderror"
                                                   placeholder="{{ __('Unbekannt') }}">
                                            @error('editAlbum') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-sm-2 d-flex align-items-end gap-1" style="height:100%">
                                            <button type="submit" class="btn btn-sm btn-primary" title="{{ __('Speichern') }}">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    wire:click="cancelEditingFile" title="{{ __('Abbrechen') }}">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" wire:model="editFadeIn"
                                                       id="editFadeIn-{{ $file->id }}">
                                                <label class="form-check-label small" for="editFadeIn-{{ $file->id }}">
                                                    {{ __('Sanft einblenden (Fade-in)') }}
                                                    <i class="bi bi-question-circle text-muted" title="{{ __('Blendet die Datei beim Ausspielen weich ein statt hart einzusetzen – z.B. für Jingles.') }}"></i>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        @endif

                        {{-- Tag-Editor (inline) --}}
                        @if($editingTagsForFileId === $file->id)
                            <div class="list-group-item bg-light border-top-0 py-2 px-3">
                                <p class="small fw-medium mb-2">{{ __('Tags für') }} „{{ $file->title }}"</p>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    @foreach($tags as $tag)
                                        <div class="form-check form-check-inline mb-0">
                                            <input class="form-check-input" type="checkbox"
                                                   id="tag-{{ $file->id }}-{{ $tag->id }}"
                                                   value="{{ $tag->id }}"
                                                   wire:model="editingTagIds">
                                            <label class="form-check-label small" for="tag-{{ $file->id }}-{{ $tag->id }}">
                                                {{ $tag->name }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" wire:click="saveFileTags">
                                        <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="cancelEditingTags">
                                        {{ __('Abbrechen') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

@script
<script>
Alpine.data('mediaPreview', () => ({
    playingId: null,
    loadingId: null,
    errorId: null,

    toggle(id, url) {
        const audio = this.$refs.audio;

        // Läuft dieser Titel bereits? → pausieren.
        if (this.playingId === id) {
            audio.pause();
            this.playingId = null;
            return;
        }

        this.errorId = null;
        this.loadingId = id;
        audio.src = url;
        audio.play()
            .then(() => {
                this.loadingId = null;
                this.playingId = id;
            })
            .catch(() => {
                this.loadingId = null;
                this.playingId = null;
                this.errorId = id;
            });
    },
}));

Alpine.data('chunkUploader', () => ({
    dragging: false,
    queue: [],
    CHUNK_SIZE: 4 * 1024 * 1024, // 4 MB – bleibt unter konservativen post_max_size/Proxy-Limits

    onDrop(event) {
        this.dragging = false;
        this.onFileInput(event.dataTransfer.files);
    },

    async onFileInput(fileList) {
        const allowed = /\.(mp3|m4a|ogg|wav|flac)$/i;
        const files = Array.from(fileList).filter(f => allowed.test(f.name));
        this.$refs.fileInput.value = '';

        // Komplette Auswahl SOFORT sichtbar machen (Status 'queued'),
        // damit man sieht was ansteht und wann alles fertig ist.
        const jobs = files.map(file => {
            const entry = {
                id: crypto.randomUUID(),
                name: file.name,
                status: 'queued',
                progress: 0,
                error: null,
            };
            this.queue.push(entry);
            return { entry, file };
        });

        // Dateien NACHEINANDER hochladen – verhindert viele parallele Requests
        // (sonst Write-Contention auf SQLite → Hänger/502 hinter dem Proxy).
        for (const { entry, file } of jobs) {
            await this.uploadFile(entry, file);
        }
    },

    async uploadFile(entry, file) {
        const totalChunks = Math.max(1, Math.ceil(file.size / this.CHUNK_SIZE));
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const url = '{{ route('media.upload.chunk') }}';

        entry.status = 'uploading';

        try {
            for (let i = 0; i < totalChunks; i++) {
                const start = i * this.CHUNK_SIZE;
                const chunk = file.slice(start, start + this.CHUNK_SIZE);

                const form = new FormData();
                form.append('file', chunk, file.name);
                form.append('file_id', entry.id);
                form.append('chunk_index', i);
                form.append('total_chunks', totalChunks);
                form.append('file_name', file.name);

                // Chunk mit bis zu 3 Versuchen senden (Netzwerk-/5xx-Fehler abfedern)
                let response, lastErr;
                for (let attempt = 1; attempt <= 3; attempt++) {
                    try {
                        response = await fetch(url, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrfToken },
                            body: form,
                        });
                        if (response.ok) break;
                        if (response.status < 500) {
                            const err = await response.json().catch(() => ({}));
                            throw new Error(err.message ?? `HTTP ${response.status}`);
                        }
                        lastErr = new Error(`HTTP ${response.status}`);
                    } catch (netErr) {
                        lastErr = netErr;
                    }
                    if (attempt < 3) await new Promise(r => setTimeout(r, attempt * 1000));
                }

                if (! response || ! response.ok) {
                    throw lastErr ?? new Error('Upload fehlgeschlagen');
                }

                const data = await response.json();
                entry.progress = Math.round(((i + 1) / totalChunks) * 100);

                if (data.done) {
                    entry.status = 'done';
                    entry.progress = 100;
                    await $wire.addPendingUpload(
                        data.path,
                        data.title ?? '',
                        data.duration ?? null,
                        file.name,
                        data.artist ?? null,
                        data.album ?? null,
                    );
                    return;
                }
            }
        } catch (e) {
            entry.status = 'error';
            entry.error = e.message;
        }
    },
}));
</script>
@endscript
