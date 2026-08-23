<div x-data="{ playing: false, loading: false }">
    <audio x-ref="audio" preload="none" class="d-none"
           @ended="playing = false"
           x-on:error="loading = false; playing = false"></audio>

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="{{ route('media.index') }}" wire:navigate class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h4 class="fw-semibold mb-0">{{ $file->title }}</h4>
                <div class="text-muted small">
                    {{ $file->artist ?: __('Unknown artist') }}
                    @if($file->album)
                        <span class="ms-2"><i class="bi bi-disc me-1"></i>{{ $file->album }}</span>
                    @endif
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm"
                    @click="
                        if (playing) { $refs.audio.pause(); playing = false; return; }
                        loading = true;
                        $refs.audio.src = '{{ route('media.preview', $file) }}';
                        $refs.audio.play().then(() => { loading = false; playing = true; }).catch(() => { loading = false; playing = false; });
                    ">
                <span x-show="loading" class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem"></span>
                <i class="bi me-1" :class="playing ? 'bi-pause-fill' : 'bi-play-fill'"></i>
                <span x-text="playing ? @js(__('Pause')) : @js(__('Preview'))"></span>
            </button>
            @if($this->mayReplace)
                <button class="btn btn-outline-danger btn-sm"
                        @click="$dispatch('confirm-dialog', { message: @js(__('Delete this file? It disappears from every station of this account.')), confirmText: @js(__('Delete')), onConfirm: () => $wire.delete() })">
                    <i class="bi bi-trash me-1"></i>{{ __('Delete') }}
                </button>
            @endif
        </div>
    </div>

    @unless($this->mayWrite)
        <div class="alert alert-secondary d-flex align-items-center gap-2 py-2">
            <i class="bi bi-eye fs-5"></i>
            <div>{{ __('You can use this library in your playlists, but not change it.') }}</div>
        </div>
    @endunless

    <div class="row g-4">
        {{-- Metadaten --}}
        <div class="col-12 col-lg-7">
            <div class="card mb-4">
                <div class="card-header fw-medium"><i class="bi bi-pencil me-1"></i>{{ __('Metadata') }}</div>
                <div class="card-body">
                    <form wire:submit="save">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label small fw-medium mb-1">{{ __('Titel') }}</label>
                                <input type="text" wire:model="title" @disabled(! $this->mayWrite)
                                       class="form-control form-control-sm @error('title') is-invalid @enderror">
                                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-medium mb-1">{{ __('Interpret') }}</label>
                                <input type="text" wire:model="artist" @disabled(! $this->mayWrite)
                                       class="form-control form-control-sm @error('artist') is-invalid @enderror"
                                       placeholder="{{ __('Unbekannt') }}">
                                @error('artist') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-medium mb-1">{{ __('Album') }}</label>
                                <input type="text" wire:model="album" @disabled(! $this->mayWrite)
                                       class="form-control form-control-sm @error('album') is-invalid @enderror"
                                       placeholder="{{ __('Unbekannt') }}">
                                @error('album') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small fw-medium mb-1">{{ __('Typ') }}</label>
                                <select wire:model="type" @disabled(! $this->mayWrite) class="form-select form-select-sm">
                                    <option value="music">{{ __('Musik') }}</option>
                                    <option value="jingle">{{ __('Jingle') }}</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 d-flex align-items-end">
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" wire:model="fadeIn"
                                           id="fadeIn" @disabled(! $this->mayWrite)>
                                    <label class="form-check-label small" for="fadeIn">
                                        {{ __('Sanft einblenden (Fade-in)') }}
                                        <i class="bi bi-question-circle text-muted" title="{{ __('Blendet die Datei beim Ausspielen weich ein statt hart einzusetzen – z.B. für Jingles.') }}"></i>
                                    </label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-medium mb-1">{{ __('Notes') }}</label>
                                <textarea wire:model="notes" rows="3" @disabled(! $this->mayWrite)
                                          class="form-control form-control-sm @error('notes') is-invalid @enderror"
                                          placeholder="{{ __('Internal notes, e.g. intro length or usage restrictions.') }}"></textarea>
                                @error('notes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            @if($tags->isNotEmpty())
                                <div class="col-12">
                                    <label class="form-label small fw-medium mb-1">{{ __('Tags') }}</label>
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach($tags as $tag)
                                            <div class="form-check form-check-inline mb-0">
                                                <input class="form-check-input" type="checkbox"
                                                       id="tag-{{ $tag->id }}" value="{{ $tag->id }}"
                                                       wire:model="tagIds" @disabled(! $this->mayWrite)>
                                                <label class="form-check-label small" for="tag-{{ $tag->id }}">{{ $tag->name }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if($this->mayWrite)
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                                </button>
                            </div>
                        @endif
                    </form>
                </div>
            </div>

            {{-- Datei ersetzen --}}
            @if($this->mayReplace)
                <div class="card mb-4" x-data="replaceUploader">
                    <div class="card-header fw-medium"><i class="bi bi-arrow-repeat me-1"></i>{{ __('Replace file') }}</div>
                    <div class="card-body">
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
                                <input class="form-check-input" type="checkbox" wire:model="adoptMetadata" id="adoptMetadata">
                                <label class="form-check-label small" for="adoptMetadata">
                                    {{ __('Take title, artist and album from the new file') }}
                                </label>
                            </div>

                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-primary" wire:click="confirmReplacement">
                                    <i class="bi bi-check-lg me-1"></i>{{ __('Replace now') }}
                                </button>
                                <button class="btn btn-sm btn-outline-secondary" wire:click="cancelReplacement">
                                    {{ __('Abbrechen') }}
                                </button>
                            </div>
                        @else
                            <div class="border rounded-3 p-4 text-center"
                                 :class="dragging ? 'border-primary bg-primary bg-opacity-10' : ''"
                                 style="border-style: dashed !important; border-color: #6c757d; cursor: pointer; transition: all .15s"
                                 @dragover.prevent="dragging = true"
                                 @dragleave.prevent="dragging = false"
                                 @drop.prevent="dragging = false; upload($event.dataTransfer.files[0])"
                                 @click="$refs.replaceInput.click()">
                                <template x-if="! busy">
                                    <div>
                                        <i class="bi bi-cloud-upload display-6 text-muted mb-2 d-block"></i>
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
                </div>

                {{-- Versionen --}}
                @if($versions->isNotEmpty())
                    <div class="card mb-4">
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
                                    <button class="btn btn-sm btn-outline-secondary text-nowrap"
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
            @endif
        </div>

        {{-- Datei-Infos --}}
        <div class="col-12 col-lg-5">
            <div class="card mb-4">
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

            {{-- Verwendung in Playlisten --}}
            <div class="card mb-4">
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

            {{-- Betroffene Rundowns --}}
            @if($this->upcomingRundowns->isNotEmpty())
                <div class="card mb-4">
                    <div class="card-header fw-medium"><i class="bi bi-calendar-week me-1"></i>{{ __('Scheduled in rundowns') }}</div>
                    <div class="list-group list-group-flush">
                        @foreach($this->upcomingRundowns as $rundown)
                            <a href="{{ route('rundown.show', ['date' => $rundown->broadcast_date->toDateString(), 'hour' => $rundown->broadcast_hour]) }}"
                               wire:navigate class="list-group-item list-group-item-action small d-flex align-items-center justify-content-between">
                                <span>
                                    {{ $rundown->broadcast_date->format('d.m.Y') }}, {{ sprintf('%02d:00', $rundown->broadcast_hour) }}
                                </span>
                                <i class="bi bi-chevron-right text-muted"></i>
                            </a>
                        @endforeach
                    </div>
                    <div class="card-footer text-muted small">
                        {{ __('These rundowns keep the version they were generated with. Regenerate them to pick up a replaced file.') }}
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@script
<script>
Alpine.data('replaceUploader', () => ({
    dragging: false,
    busy: false,
    progress: 0,
    error: null,
    CHUNK_SIZE: 4 * 1024 * 1024, // 4 MB – bleibt unter konservativen post_max_size/Proxy-Limits

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
