<div>
    @php
        $weekdays = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
    @endphp

    <div class="modal fade" tabindex="-1" wire:ignore.self
         x-data="mediaFileModal"
         @media-file-modal-opened.window="show()"
         @media-file-modal-closed.window="modal.hide()">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                @if($file)
                    <div class="modal-header align-items-start">
                        <div class="d-flex align-items-center gap-3">
                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-circle p-0 flex-shrink-0"
                                    style="width:36px;height:36px"
                                    @click="togglePreview('{{ route('media.preview', $file) }}')">
                                <span x-show="loading" class="spinner-border spinner-border-sm" style="width:.8rem;height:.8rem"></span>
                                <i x-show="! loading" class="bi" :class="playing ? 'bi-pause-fill' : 'bi-play-fill'"></i>
                            </button>
                            <div>
                                <h5 class="modal-title mb-0">{{ $file->title }}</h5>
                                <div class="text-muted small">
                                    {{ $file->artist ?: __('Unknown artist') }}
                                    @if($file->album)
                                        <span class="ms-2"><i class="bi bi-disc me-1"></i>{{ $file->album }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Schließen') }}"></button>
                    </div>

                    <div class="modal-body">
                        @unless($this->mayWrite)
                            <div class="alert alert-secondary d-flex align-items-center gap-2 py-2">
                                <i class="bi bi-eye fs-5"></i>
                                <div>{{ __('You can use this library in your playlists, but not change it.') }}</div>
                            </div>
                        @endunless

                        <div class="row g-4">
                            <div class="col-12 col-lg-7">
                                {{-- Metadata --}}
                                <form wire:submit="save" id="mediaFileForm">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label small fw-medium mb-1" for="modal-title">{{ __('Titel') }}</label>
                                            <input type="text" id="modal-title" wire:model="title" @disabled(! $this->mayWrite)
                                                   class="form-control form-control-sm @error('title') is-invalid @enderror">
                                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label class="form-label small fw-medium mb-1" for="modal-artist">{{ __('Interpret') }}</label>
                                            <input type="text" id="modal-artist" wire:model="artist" @disabled(! $this->mayWrite)
                                                   class="form-control form-control-sm @error('artist') is-invalid @enderror"
                                                   placeholder="{{ __('Unbekannt') }}">
                                            @error('artist') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label class="form-label small fw-medium mb-1" for="modal-album">{{ __('Album') }}</label>
                                            <input type="text" id="modal-album" wire:model="album" @disabled(! $this->mayWrite)
                                                   class="form-control form-control-sm @error('album') is-invalid @enderror"
                                                   placeholder="{{ __('Unbekannt') }}">
                                            @error('album') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label class="form-label small fw-medium mb-1" for="modal-type">{{ __('Typ') }}</label>
                                            <select wire:model="type" id="modal-type" @disabled(! $this->mayWrite) class="form-select form-select-sm">
                                                <option value="music">{{ __('Musik') }}</option>
                                                <option value="jingle">{{ __('Jingle') }}</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-6 d-flex align-items-end">
                                            <div class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" wire:model="fadeIn"
                                                       id="modal-fade-in" @disabled(! $this->mayWrite)>
                                                <label class="form-check-label small" for="modal-fade-in">
                                                    {{ __('Sanft einblenden (Fade-in)') }}
                                                    <i class="bi bi-question-circle text-muted" title="{{ __('Blendet die Datei beim Ausspielen weich ein statt hart einzusetzen – z.B. für Jingles.') }}"></i>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-medium mb-1" for="modal-notes">{{ __('Notes') }}</label>
                                            <textarea wire:model="notes" id="modal-notes" rows="2" @disabled(! $this->mayWrite)
                                                      class="form-control form-control-sm @error('notes') is-invalid @enderror"
                                                      placeholder="{{ __('Internal notes, e.g. intro length or usage restrictions.') }}"></textarea>
                                            @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                        </div>
                                    </div>

                                    {{-- Tags --}}
                                    <div class="mt-4">
                                        <label class="form-label small fw-medium mb-1">{{ __('Tags') }}</label>
                                        @if($tags->isEmpty())
                                            <p class="text-muted small mb-0">{{ __('No tags yet. Create them from the library list.') }}</p>
                                        @else
                                            <div class="d-flex flex-wrap gap-2">
                                                @foreach($tags as $tag)
                                                    <div class="form-check form-check-inline mb-0">
                                                        <input class="form-check-input" type="checkbox"
                                                               id="modal-tag-{{ $tag->id }}" value="{{ $tag->id }}"
                                                               wire:model="tagIds" @disabled(! $this->mayWrite)>
                                                        <label class="form-check-label small" for="modal-tag-{{ $tag->id }}">{{ $tag->name }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Airtime windows --}}
                                    <div class="mt-4">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <label class="form-label small fw-medium mb-0">
                                                <i class="bi bi-clock-history me-1"></i>{{ __('Airtime windows') }}
                                            </label>
                                            @if($this->mayWrite)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="addAirtimeWindow">
                                                    <i class="bi bi-plus-lg me-1"></i>{{ __('Add window') }}
                                                </button>
                                            @endif
                                        </div>
                                        <p class="text-muted small">
                                            {{ __('Limits when fill and random elements may pick this file. Without a window it can air at any time. Elements you place in a playlist yourself are never blocked.') }}
                                        </p>

                                        @forelse($airtimeWindows as $index => $window)
                                            <div class="border rounded-3 p-2 mb-2" wire:key="airtime-window-{{ $index }}">
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <div class="btn-group btn-group-sm flex-wrap" role="group">
                                                        @foreach($weekdays as $isoDay => $label)
                                                            <input type="checkbox" class="btn-check" autocomplete="off"
                                                                   id="window-{{ $index }}-day-{{ $isoDay }}"
                                                                   value="{{ $isoDay }}"
                                                                   wire:model.live="airtimeWindows.{{ $index }}.days"
                                                                   @disabled(! $this->mayWrite)>
                                                            <label class="btn btn-outline-secondary" for="window-{{ $index }}-day-{{ $isoDay }}">{{ $label }}</label>
                                                        @endforeach
                                                    </div>

                                                    <div class="input-group input-group-sm" style="width:auto">
                                                        <span class="input-group-text">{{ __('Von') }}</span>
                                                        <input type="time" class="form-control @error('airtimeWindows.'.$index.'.from') is-invalid @enderror"
                                                               style="width:7rem"
                                                               wire:model="airtimeWindows.{{ $index }}.from" @disabled(! $this->mayWrite)>
                                                        <span class="input-group-text">{{ __('Bis') }}</span>
                                                        <input type="time" class="form-control @error('airtimeWindows.'.$index.'.to') is-invalid @enderror"
                                                               style="width:7rem"
                                                               wire:model="airtimeWindows.{{ $index }}.to" @disabled(! $this->mayWrite)>
                                                    </div>

                                                    @if($this->mayWrite)
                                                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto"
                                                                wire:click="removeAirtimeWindow({{ $index }})"
                                                                title="{{ __('Remove window') }}">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    @endif
                                                </div>

                                                @if(empty($window['days']))
                                                    <div class="text-muted mt-1" style="font-size:.75rem">
                                                        <i class="bi bi-info-circle me-1"></i>{{ __('No weekday picked: the window counts for every day.') }}
                                                    </div>
                                                @endif
                                                @if(($window['from'] ?? '') > ($window['to'] ?? '') && ($window['from'] ?? '') !== ($window['to'] ?? ''))
                                                    <div class="text-muted mt-1" style="font-size:.75rem">
                                                        <i class="bi bi-moon me-1"></i>{{ __('Runs past midnight into the next day.') }}
                                                    </div>
                                                @endif
                                                @error('airtimeWindows.'.$index.'.from') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                                @error('airtimeWindows.'.$index.'.to') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                            </div>
                                        @empty
                                            <p class="text-muted small fst-italic mb-0">{{ __('Airs around the clock.') }}</p>
                                        @endforelse
                                    </div>
                                </form>

                                {{-- Replace file --}}
                                @if($this->mayReplace)
                                    <div class="mt-4 pt-3 border-top" x-data="replaceUploader">
                                        <label class="form-label small fw-medium mb-1">
                                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('Replace file') }}
                                        </label>
                                        <p class="text-muted small">
                                            {{ __('The new file takes the place of this entry: playlists, tags and metadata stay as they are. Rundowns that are already generated keep playing the old version until you regenerate them.') }}
                                        </p>

                                        @if($pendingReplacement)
                                            <div class="alert alert-warning py-2">
                                                <div class="fw-medium small mb-1">
                                                    <i class="bi bi-file-earmark-music me-1"></i>{{ $pendingReplacement['filename'] }}
                                                </div>
                                                <div class="small text-muted">
                                                    @if($pendingReplacement['duration'])
                                                        <i class="bi bi-clock me-1"></i>{{ sprintf('%d:%02d', intdiv($pendingReplacement['duration'], 60), $pendingReplacement['duration'] % 60) }}
                                                        <span class="mx-1">&middot;</span>
                                                    @endif
                                                    {{ __('Previous length: :duration', ['duration' => $file->durationFormatted() ?? '–']) }}
                                                </div>
                                            </div>

                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" wire:model="adoptMetadata" id="modal-adopt-metadata">
                                                <label class="form-check-label small" for="modal-adopt-metadata">
                                                    {{ __('Take title, artist and album from the new file') }}
                                                </label>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-primary" wire:click="confirmReplacement">
                                                    <i class="bi bi-check-lg me-1"></i>{{ __('Replace now') }}
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelReplacement">
                                                    {{ __('Abbrechen') }}
                                                </button>
                                            </div>
                                        @else
                                            <div class="border rounded-3 p-3 text-center"
                                                 :class="dragging ? 'border-primary bg-primary bg-opacity-10' : ''"
                                                 style="border-style: dashed !important; border-color: #6c757d; cursor: pointer; transition: all .15s"
                                                 @dragover.prevent="dragging = true"
                                                 @dragleave.prevent="dragging = false"
                                                 @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])"
                                                 @click="$refs.replaceInput.click()">
                                                <template x-if="! busy">
                                                    <div>
                                                        <i class="bi bi-cloud-upload fs-3 text-muted d-block"></i>
                                                        <p class="mb-1 fw-medium small">{{ __('Drop the new file here') }}</p>
                                                        <p class="text-muted small mb-0">{{ __('oder klicken zum Auswählen') }} &ndash; MP3, M4A, OGG, WAV, FLAC</p>
                                                    </div>
                                                </template>
                                                <template x-if="busy">
                                                    <div>
                                                        <div class="progress mb-2" style="height:6px">
                                                            <div class="progress-bar" :style="`width: ${progress}%`"></div>
                                                        </div>
                                                        <p class="text-muted small mb-0" x-text="`${progress}%`"></p>
                                                    </div>
                                                </template>
                                                <input type="file" x-ref="replaceInput" accept=".mp3,.m4a,.ogg,.wav,.flac"
                                                       class="d-none" @change="upload($event.target.files[0])">
                                            </div>
                                            <template x-if="error">
                                                <p class="text-danger small mt-2 mb-0" x-text="error"></p>
                                            </template>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            {{-- File facts --}}
                            <div class="col-12 col-lg-5">
                                <div class="card mb-3">
                                    <div class="card-header fw-medium"><i class="bi bi-info-circle me-1"></i>{{ __('File') }}</div>
                                    <div class="card-body small">
                                        <dl class="row mb-0">
                                            <dt class="col-5 fw-normal text-muted">{{ __('Filename') }}</dt>
                                            <dd class="col-7 text-truncate" title="{{ $file->filename() }}">{{ $file->filename() }}</dd>

                                            <dt class="col-5 fw-normal text-muted">{{ __('Dauer') }}</dt>
                                            <dd class="col-7">{{ $file->durationFormatted() ?? '–' }}</dd>

                                            <dt class="col-5 fw-normal text-muted">{{ __('Loudness') }}</dt>
                                            <dd class="col-7">
                                                @if($file->loudness_lufs !== null)
                                                    {{ number_format($file->loudness_lufs, 1) }} LUFS
                                                    @php $gain = $file->loudnessGainDb(); @endphp
                                                    @if($gain !== null)
                                                        <span class="badge bg-light text-dark border ms-1">{{ sprintf('%+.1f dB', $gain) }}</span>
                                                    @endif
                                                    <div class="text-muted" style="font-size:.75rem">
                                                        {{ __('True peak: :tp dBTP', ['tp' => $file->loudness_true_peak !== null ? number_format($file->loudness_true_peak, 1) : '–']) }}
                                                    </div>
                                                @else
                                                    <span class="fst-italic text-muted">{{ __('Lautheit ausstehend') }}</span>
                                                @endif
                                            </dd>

                                            <dt class="col-5 fw-normal text-muted">{{ __('Added') }}</dt>
                                            <dd class="col-7 mb-0">{{ $file->created_at?->format('d.m.Y H:i') ?? '–' }}</dd>
                                        </dl>
                                    </div>
                                </div>

                                {{-- Used in playlists --}}
                                <div class="card mb-3">
                                    <div class="card-header fw-medium"><i class="bi bi-collection me-1"></i>{{ __('Used in playlists') }}</div>
                                    @if($this->usages->isEmpty())
                                        <div class="card-body text-muted small mb-0">{{ __('Not used in any playlist yet.') }}</div>
                                    @else
                                        <div class="list-group list-group-flush">
                                            @foreach($this->usages as $usage)
                                                <a href="{{ route('playlist.manager', $usage->playlist) }}" wire:navigate
                                                   class="list-group-item list-group-item-action small d-flex align-items-center justify-content-between">
                                                    <span class="text-truncate">{{ $usage->playlist->name }}</span>
                                                    <i class="bi bi-chevron-right text-muted"></i>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                {{-- Affected rundowns --}}
                                @if($this->upcomingRundowns->isNotEmpty())
                                    <div class="card mb-3">
                                        <div class="card-header fw-medium"><i class="bi bi-calendar-week me-1"></i>{{ __('Scheduled in rundowns') }}</div>
                                        <div class="list-group list-group-flush">
                                            @foreach($this->upcomingRundowns as $rundown)
                                                <a href="{{ route('rundown.show', ['date' => $rundown->broadcast_date->toDateString(), 'hour' => $rundown->broadcast_hour]) }}"
                                                   wire:navigate class="list-group-item list-group-item-action small d-flex align-items-center justify-content-between">
                                                    <span>{{ $rundown->broadcast_date->format('d.m.Y') }}, {{ sprintf('%02d:00', $rundown->broadcast_hour) }}</span>
                                                    <i class="bi bi-chevron-right text-muted"></i>
                                                </a>
                                            @endforeach
                                        </div>
                                        <div class="card-footer text-muted small">
                                            {{ __('These rundowns keep the version they were generated with. Regenerate them to pick up a replaced file.') }}
                                        </div>
                                    </div>
                                @endif

                                {{-- Versions --}}
                                @if($this->mayReplace && $versions->isNotEmpty())
                                    <div class="card mb-3">
                                        <div class="card-header fw-medium"><i class="bi bi-clock-history me-1"></i>{{ __('Previous versions') }}</div>
                                        <div class="list-group list-group-flush">
                                            @foreach($versions as $version)
                                                <div class="list-group-item d-flex align-items-center gap-2 py-2">
                                                    <i class="bi bi-file-earmark-music text-muted"></i>
                                                    <div class="flex-grow-1 overflow-hidden">
                                                        <div class="small text-truncate">{{ $version->original_filename ?? $version->filename() }}</div>
                                                        <div class="text-muted" style="font-size:.75rem">
                                                            {{ __('Replaced on :date', ['date' => $version->created_at->format('d.m.Y H:i')]) }}
                                                            @if($version->replacedBy)
                                                                <span class="ms-1">{{ __('by :name', ['name' => $version->replacedBy->name]) }}</span>
                                                            @endif
                                                            @if($version->durationFormatted())
                                                                <span class="ms-2"><i class="bi bi-clock me-1"></i>{{ $version->durationFormatted() }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                            @click="$dispatch('confirm-dialog', { message: @js(__('Restore this version? The current file moves to the version history.')), confirmText: @js(__('Restore')), onConfirm: () => $wire.restoreVersion({{ $version->id }}) })">
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Restore') }}
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>
                                        <div class="card-footer text-muted small">
                                            {{ __('Old versions are removed automatically once no rundown refers to them any more.') }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        @if($this->mayReplace)
                            <button type="button" class="btn btn-outline-danger btn-sm me-auto"
                                    @click="$dispatch('confirm-dialog', { message: @js(__('Delete this file? It disappears from every station of this account.')), confirmText: @js(__('Delete')), onConfirm: () => $wire.delete() })">
                                <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
                            </button>
                        @endif
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">{{ __('Schließen') }}</button>
                        @if($this->mayWrite)
                            <button type="submit" form="mediaFileForm" class="btn btn-primary btn-sm">
                                <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('mediaFileModal', () => ({
    modal: null,
    audio: null,
    playing: false,
    loading: false,

    init() {
        this.modal = bootstrap.Modal.getOrCreateInstance(this.$el);

        // Closing through the backdrop, the X or Escape has to reach the component too,
        // otherwise the deep link stays in the URL and the dialog cannot reopen.
        this.$el.addEventListener('hidden.bs.modal', () => {
            this.stopPreview();
            if ($wire.openFileId !== null) {
                $wire.close();
            }
        });

        if ($wire.openFileId !== null) {
            this.show();
        }
    },

    show() {
        this.modal.show();
    },

    stopPreview() {
        if (this.audio) {
            this.audio.pause();
        }
        this.playing = false;
        this.loading = false;
    },

    togglePreview(url) {
        if (! this.audio) {
            this.audio = new Audio();
            this.audio.addEventListener('ended', () => { this.playing = false; });
            this.audio.addEventListener('error', () => { this.playing = false; this.loading = false; });
        }

        if (this.playing) {
            this.audio.pause();
            this.playing = false;
            return;
        }

        this.loading = true;
        this.audio.src = url;
        this.audio.play()
            .then(() => { this.loading = false; this.playing = true; })
            .catch(() => { this.loading = false; this.playing = false; });
    },
}));

Alpine.data('replaceUploader', () => ({
    dragging: false,
    busy: false,
    progress: 0,
    error: null,
    CHUNK_SIZE: 4 * 1024 * 1024, // 4 MB, stays under conservative post_max_size and proxy limits

    async upload(file) {
        if (! file || this.busy) return;

        if (! /\.(mp3|m4a|ogg|wav|flac)$/i.test(file.name)) {
            this.error = @js(__('Unsupported file type.'));
            return;
        }

        this.error = null;
        this.busy = true;
        this.progress = 0;

        const fileId = crypto.randomUUID();
        const totalChunks = Math.max(1, Math.ceil(file.size / this.CHUNK_SIZE));
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const url = '{{ route('media.upload.chunk') }}';

        try {
            for (let i = 0; i < totalChunks; i++) {
                const start = i * this.CHUNK_SIZE;
                const form = new FormData();
                form.append('file', file.slice(start, start + this.CHUNK_SIZE), file.name);
                form.append('file_id', fileId);
                form.append('chunk_index', i);
                form.append('total_chunks', totalChunks);
                form.append('file_name', file.name);

                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: form,
                });

                if (! response.ok) {
                    const err = await response.json().catch(() => ({}));
                    throw new Error(err.message ?? `HTTP ${response.status}`);
                }

                const data = await response.json();
                this.progress = Math.round(((i + 1) / totalChunks) * 100);

                if (data.done) {
                    await $wire.addPendingReplacement(
                        data.path,
                        data.title ?? '',
                        data.duration ?? null,
                        file.name,
                        data.artist ?? null,
                        data.album ?? null,
                    );
                    break;
                }
            }
        } catch (e) {
            this.error = e.message;
        } finally {
            this.busy = false;
            this.$refs.replaceInput.value = '';
        }
    },
}));
</script>
@endscript
