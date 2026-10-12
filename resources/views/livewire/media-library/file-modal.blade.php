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

                        {{-- Waveform and cue points --}}
                        <div class="card mb-4">
                            <div class="card-body p-2">
                                @if($file->waveform_path)
                                    @php $fileHash = substr(md5($file->file_path), 0, 12); @endphp
                                    <div wire:ignore wire:key="waveform-{{ $file->id }}-{{ $fileHash }}"
                                         x-data="mediaWaveform(@js(route('media.waveform', ['mediaFile' => $file, 'v' => $fileHash])), @js(route('media.preview', $file)), @js($this->mayWrite))">
                                        <div x-ref="waveform"></div>
                                        <div class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
                                            <span class="font-monospace">
                                                <span x-text="formatTime(currentTime)">0:00.0</span>
                                                <span class="text-muted">/ <span x-text="formatTime(duration)">0:00.0</span></span>
                                            </span>
                                            <span class="badge bg-light text-dark border" x-show="duration > 0" x-cloak
                                                  title="{{ __('What goes on air, from cue in to cue out') }}">
                                                <i class="bi bi-broadcast me-1"></i><span x-text="formatTime(playbackLength())"></span>
                                            </span>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-secondary" @click="playFromCueIn()" title="{{ __('Play from cue in') }}">
                                                    <i class="bi bi-skip-start-fill"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" @click="previewEnding()" title="{{ __('Preview the ending up to cue out') }}">
                                                    <i class="bi bi-skip-end-fill"></i>
                                                </button>
                                            </div>
                                            <span x-show="failed" x-cloak class="text-danger">
                                                <i class="bi bi-exclamation-triangle me-1"></i>{{ __('The waveform could not be loaded.') }}
                                            </span>
                                            <div class="d-flex align-items-center gap-2 ms-auto">
                                                <i class="bi bi-zoom-out text-muted"></i>
                                                <input type="range" class="form-range" style="width:10rem"
                                                       min="0" max="300" step="5" x-model.number="zoom" @input="applyZoom()"
                                                       aria-label="{{ __('Zoom') }}">
                                                <i class="bi bi-zoom-in text-muted"></i>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <div wire:poll.5s class="d-flex align-items-center justify-content-center gap-2 text-muted small py-4">
                                        <span class="spinner-border spinner-border-sm"></span>
                                        {{ __('Generating waveform...') }}
                                    </div>
                                @endif
                            </div>
                            <div class="card-footer">
                                <div class="row g-2">
                                    @foreach([
                                        ['field' => 'cueIn', 'label' => __('Cue in'), 'hint' => __('Where the file starts on air.'), 'placeholder' => '0', 'playhead' => true],
                                        ['field' => 'fadeIn', 'label' => __('Fade in'), 'hint' => __('Seconds faded in from cue in.'), 'placeholder' => '0', 'playhead' => false],
                                        ['field' => 'fadeOut', 'label' => __('Fade out'), 'hint' => __('Seconds faded out up to cue out.'), 'placeholder' => '0', 'playhead' => false],
                                        ['field' => 'cueOut', 'label' => __('Cue out'), 'hint' => __('Where the file ends on air.'), 'placeholder' => __('End'), 'playhead' => true],
                                        ['field' => 'segue', 'label' => __('Segue'), 'hint' => __('Where the next element starts while this one fades out underneath (start next).'), 'placeholder' => __('None'), 'playhead' => true],
                                    ] as $cueField)
                                        <div class="col-6 col-md">
                                            <label class="form-label small fw-medium mb-1" for="modal-{{ $cueField['field'] }}" title="{{ $cueField['hint'] }}">{{ $cueField['label'] }}</label>
                                            <div class="input-group input-group-sm has-validation">
                                                <input type="number" step="0.01" min="0" id="modal-{{ $cueField['field'] }}" form="mediaFileForm"
                                                       wire:model="{{ $cueField['field'] }}" @disabled(! $this->mayWrite)
                                                       class="form-control @error($cueField['field']) is-invalid @enderror"
                                                       placeholder="{{ $cueField['placeholder'] }}">
                                                <span class="input-group-text">s</span>
                                                @if($cueField['playhead'] && $this->mayWrite && $file->waveform_path)
                                                    <button type="button" class="btn btn-outline-secondary"
                                                            @click="$dispatch('waveform-set-at-playhead', { field: @js($cueField['field']) })"
                                                            title="{{ __('Set to the playhead position') }}">
                                                        <i class="bi bi-geo-alt"></i>
                                                    </button>
                                                @endif
                                                @error($cueField['field']) <div class="invalid-feedback">{{ $message }}</div> @enderror
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <p class="text-muted mb-0 flex-grow-1" style="font-size:.75rem">
                                        {{ __('Drag the markers in the waveform or type the seconds. Cue points belong to this version of the file: a replaced file starts without them.') }}
                                        @unless($station->segues_enabled)
                                            {{ __('This station does not use segues: the segue point only applies on stations that have them switched on.') }}
                                        @endunless
                                    </p>
                                    @if($this->mayWrite)
                                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap"
                                                wire:click="analyzeCuePoints" wire:loading.attr="disabled" wire:target="analyzeCuePoints"
                                                title="{{ __('Suggest cue in and cue out from the silence at the start and end') }}">
                                            <span wire:loading.remove wire:target="analyzeCuePoints"><i class="bi bi-magic me-1"></i></span>
                                            <span wire:loading wire:target="analyzeCuePoints" class="spinner-border spinner-border-sm me-1"></span>
                                            {{ __('Detect from silence') }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>

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
                                                <option value="voicetrack">{{ __('Voice track') }}</option>
                                            </select>
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

                                    {{-- Run time --}}
                                    <div class="mt-4">
                                        <label class="form-label small fw-medium mb-1">
                                            <i class="bi bi-calendar-range me-1"></i>{{ __('Run time') }}
                                        </label>
                                        <p class="text-muted small">
                                            {{ __('Fill and random elements only pick this file between these dates, for example a Christmas jingle in December or a trailer that expires tonight. Either end may stay empty.') }}
                                        </p>
                                        <div class="row g-2">
                                            <div class="col-12 col-sm-6">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">{{ __('From') }}</span>
                                                    <input type="datetime-local" wire:model="airableFrom" @disabled(! $this->mayWrite)
                                                           class="form-control @error('airableFrom') is-invalid @enderror"
                                                           aria-label="{{ __('Run time start') }}">
                                                </div>
                                                @error('airableFrom') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                            </div>
                                            <div class="col-12 col-sm-6">
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">{{ __('Until') }}</span>
                                                    <input type="datetime-local" wire:model="airableUntil" @disabled(! $this->mayWrite)
                                                           class="form-control @error('airableUntil') is-invalid @enderror"
                                                           aria-label="{{ __('Run time end') }}">
                                                </div>
                                                @error('airableUntil') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                                            </div>
                                        </div>
                                        @if($file?->hasExpired())
                                            <div class="text-danger mt-1" style="font-size:.75rem">
                                                <i class="bi bi-calendar-x me-1"></i>{{ __('The run time is over: fill and random elements no longer pick this file.') }}
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

    /**
     * One audio element for the play button and the waveform, so both stay in sync:
     * clicking into the waveform seeks the same playback the button controls.
     */
    ensureAudio() {
        if (! this.audio) {
            this.audio = new Audio();
            this.audio.preload = 'metadata';
            this.audio.addEventListener('play', () => { this.playing = true; });
            this.audio.addEventListener('pause', () => { this.playing = false; });
            this.audio.addEventListener('ended', () => { this.playing = false; });
            this.audio.addEventListener('error', () => { this.playing = false; this.loading = false; });
        }

        return this.audio;
    },

    togglePreview(url) {
        const audio = this.ensureAudio();

        if (this.playing) {
            audio.pause();
            return;
        }

        // Keep the position when the waveform already loaded this file.
        if (audio.src !== url) {
            audio.src = url;
        }

        this.loading = true;
        audio.play()
            .then(() => { this.loading = false; })
            .catch(() => { this.loading = false; });
    },
}));

/**
 * Waveform and cue editor of the open file. The peaks are precomputed on the server, so
 * the browser never decodes the audio itself; it only streams it for playback.
 *
 * The four markers (cue in, fade in end, fade out start, cue out) mirror the Livewire
 * fields below the waveform: dragging a marker writes the field, typing into a field
 * moves the marker. Fades are stored as durations measured from the cue points.
 */
Alpine.data('mediaWaveform', (waveformUrl, previewUrl, editable) => ({
    wavesurfer: null,
    regions: null,
    markers: {},
    shades: {},
    cues: { cueIn: 0, cueOut: 0, fadeIn: 0, fadeOut: 0, segue: null },
    currentTime: 0,
    duration: 0,
    zoom: 0,
    failed: false,
    destroyed: false,
    stopAtCueOut: false,

    /** Shortest stretch that has to stay between cue in and cue out, in seconds. */
    MIN_LENGTH: 0.1,

    async init() {
        let data;

        try {
            const response = await fetch(waveformUrl, { headers: { Accept: 'application/json' } });
            if (! response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            data = await response.json();
        } catch (e) {
            this.failed = true;
            return;
        }

        // The dialog may have closed while the peaks were loading.
        if (this.destroyed) {
            return;
        }

        const css = getComputedStyle(document.documentElement);
        this.color = (name) => css.getPropertyValue(name).trim();

        this.duration = data.duration;
        this.readCues();

        this.regions = WaveSurferRegions.create();
        this.wavesurfer = WaveSurfer.create({
            container: this.$refs.waveform,
            media: this.ensureAudio(),
            url: previewUrl,
            peaks: [data.peaks.map((peak) => peak / 255)],
            duration: data.duration,
            normalize: true,
            height: 96,
            waveColor: this.color('--bs-secondary-color'),
            progressColor: this.color('--bs-primary'),
            cursorColor: this.color('--bs-emphasis-color'),
            cursorWidth: 1,
            dragToSeek: true,
            autoScroll: true,
            autoCenter: true,
            plugins: [
                WaveSurferTimeline.create({
                    height: 16,
                    style: { fontSize: '10px', color: this.color('--bs-secondary-color') },
                }),
                WaveSurferHover.create({
                    lineColor: this.color('--bs-primary'),
                    labelBackground: this.color('--bs-dark'),
                    labelColor: '#fff',
                    labelSize: '10px',
                }),
                this.regions,
            ],
        });

        this.wavesurfer.on('timeupdate', (time) => {
            this.currentTime = time;

            if (this.stopAtCueOut && time >= this.cues.cueOut) {
                this.stopAtCueOut = false;
                this.wavesurfer.pause();
            }
        });

        this.wavesurfer.once('decode', () => this.buildRegions());

        this.regions.on('region-update', (region) => this.moveMarker(region.id, region.start, false));
        this.regions.on('region-updated', (region) => this.moveMarker(region.id, region.start, true));

        // Typing into the fields below moves the markers.
        ['cueIn', 'cueOut', 'fadeIn', 'fadeOut', 'segue'].forEach((field) => {
            $wire.$watch(field, () => {
                if (this.destroyed || ! this.regions) {
                    return;
                }
                this.readCues();
                this.layout();
            });
        });

        this.onSetAtPlayhead = (event) => {
            if (this.wavesurfer && editable) {
                this.moveMarker(event.detail.field, this.wavesurfer.getCurrentTime(), true);
            }
        };
        window.addEventListener('waveform-set-at-playhead', this.onSetAtPlayhead);
    },

    /** The Livewire fields as numbers, defaults for empty or unusable values. */
    readCues() {
        const number = (value) => {
            const parsed = parseFloat(value);
            return Number.isFinite(parsed) ? parsed : null;
        };
        const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
        const end = this.duration;

        const cueIn = clamp(number($wire.cueIn) ?? 0, 0, end);
        let cueOut = clamp(number($wire.cueOut) ?? end, 0, end);

        if (cueOut <= cueIn) {
            cueOut = end;
        }

        const segue = number($wire.segue);

        this.cues = {
            cueIn,
            cueOut,
            fadeIn: Math.max(0, number($wire.fadeIn) ?? 0),
            fadeOut: Math.max(0, number($wire.fadeOut) ?? 0),
            segue: segue === null ? null : this.clampSegue(segue, cueIn, cueOut),
        };
    },

    /** The segue point stays strictly between cue in and cue out. */
    clampSegue(position, cueIn, cueOut) {
        return Math.min(Math.max(position, cueIn + this.MIN_LENGTH), cueOut - this.MIN_LENGTH);
    },

    /** Writes the cue points back to Livewire; defaults become empty fields. */
    writeCues() {
        const tolerance = 0.005;
        const round = (value) => String(Math.round(value * 100) / 100);

        $wire.cueIn = this.cues.cueIn > tolerance ? round(this.cues.cueIn) : '';
        $wire.cueOut = this.cues.cueOut < this.duration - tolerance ? round(this.cues.cueOut) : '';
        $wire.fadeIn = this.cues.fadeIn > tolerance ? round(this.cues.fadeIn) : '';
        $wire.fadeOut = this.cues.fadeOut > tolerance ? round(this.cues.fadeOut) : '';
        $wire.segue = this.cues.segue !== null ? round(this.cues.segue) : '';
    },

    /**
     * Applies a new marker position, clamped so the points keep their order: cue in,
     * fade in end, fade out start, cue out. While dragging, the dragged marker itself is
     * left alone; it snaps to the clamped position when the drag ends.
     */
    moveMarker(id, position, done) {
        const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
        const cues = { ...this.cues };

        switch (id) {
            case 'cueIn':
                cues.cueIn = clamp(position, 0, cues.cueOut - this.MIN_LENGTH);
                break;
            case 'cueOut':
                cues.cueOut = clamp(position, cues.cueIn + this.MIN_LENGTH, this.duration);
                break;
            case 'fadeIn':
                cues.fadeIn = clamp(position - cues.cueIn, 0, cues.cueOut - cues.fadeOut - cues.cueIn);
                break;
            case 'fadeOut':
                cues.fadeOut = clamp(cues.cueOut - position, 0, cues.cueOut - cues.cueIn - cues.fadeIn);
                break;
            case 'segue':
                cues.segue = position;
                break;
            default:
                return;
        }

        // Moving a cue point drags the segue point along when it would fall outside.
        if (cues.segue !== null) {
            cues.segue = this.clampSegue(cues.segue, cues.cueIn, cues.cueOut);
        }

        // A shorter range squeezes the fades rather than letting them overlap.
        const playable = cues.cueOut - cues.cueIn;
        cues.fadeIn = Math.min(cues.fadeIn, playable);
        cues.fadeOut = Math.min(cues.fadeOut, playable - cues.fadeIn);

        this.cues = cues;
        this.layout(done ? null : id);

        if (done) {
            this.writeCues();
        }
    },

    buildRegions() {
        if (this.destroyed) {
            return;
        }

        const rgba = (name, alpha) => `rgba(${this.color(name)}, ${alpha})`;
        const label = (text, background, bottom) => {
            const element = document.createElement('div');
            element.textContent = text;
            element.style.cssText = [
                'position:absolute', bottom ? 'bottom:0' : 'top:0', 'left:0',
                'font-size:10px', 'line-height:14px', 'padding:0 4px', 'border-radius:2px',
                'white-space:nowrap', 'color:#fff', `background:${background}`,
                `cursor:${editable ? 'ew-resize' : 'default'}`,
            ].join(';');
            return element;
        };

        // Shades first so the markers sit on top of them.
        this.shades.play = this.addShade(0, this.duration, rgba('--bs-primary-rgb', 0.07));
        this.shades.fadeIn = this.addShade(0, this.duration, rgba('--bs-warning-rgb', 0.25));
        this.shades.fadeOut = this.addShade(0, this.duration, rgba('--bs-warning-rgb', 0.25));
        this.shades.segue = this.addShade(0, this.duration, rgba('--bs-info-rgb', 0.25));

        const markers = [
            ['fadeIn', @js(__('Fade in')), this.color('--bs-warning'), true],
            ['fadeOut', @js(__('Fade out')), this.color('--bs-warning'), true],
            ['cueIn', @js(__('Cue in')), this.color('--bs-success'), false],
            ['cueOut', @js(__('Cue out')), this.color('--bs-danger'), false],
            ['segue', @js(__('Segue')), this.color('--bs-info'), true],
        ];

        markers.forEach(([id, text, color, bottom]) => {
            this.markers[id] = this.regions.addRegion({
                id,
                start: 0,
                drag: editable,
                resize: false,
                color,
                content: label(text, color, bottom),
            });
        });

        this.layout();
    },

    /** A non interactive background region; clicks go through to the waveform. */
    addShade(start, end, color) {
        const region = this.regions.addRegion({ start, end, color, drag: false, resize: false });

        if (region.element) {
            region.element.style.pointerEvents = 'none';
        }

        return region;
    },

    /** Moves markers and shades to the current cue points. */
    layout(skipMarker = null) {
        if (! this.markers.cueIn) {
            return;
        }

        const { cueIn, cueOut, fadeIn, fadeOut, segue } = this.cues;
        const positions = {
            cueIn,
            fadeIn: cueIn + fadeIn,
            fadeOut: cueOut - fadeOut,
            cueOut,
            segue: segue ?? cueOut,
        };

        Object.entries(positions).forEach(([id, position]) => {
            if (id !== skipMarker) {
                this.markers[id].setOptions({ start: position, end: position });
            }
        });

        // Without a segue point there is no marker to drag; it is set with the field or
        // the playhead button.
        if (this.markers.segue.element) {
            this.markers.segue.element.style.display = segue === null ? 'none' : '';
        }

        // A region of zero length would turn into a marker; hide empty shades instead.
        const shade = (region, start, end) => {
            region.setOptions({ start, end: Math.max(end, start + 0.001) });
            if (region.element) {
                region.element.style.display = end - start > 0.001 ? '' : 'none';
            }
        };

        shade(this.shades.play, cueIn, cueOut);
        shade(this.shades.fadeIn, cueIn, cueIn + fadeIn);
        shade(this.shades.fadeOut, cueOut - fadeOut, cueOut);
        shade(this.shades.segue, segue ?? cueOut, cueOut);
    },

    playbackLength() {
        return Math.max(0, this.cues.cueOut - this.cues.cueIn);
    },

    playFromCueIn() {
        if (! this.wavesurfer) {
            return;
        }
        this.stopAtCueOut = false;
        this.wavesurfer.setTime(this.cues.cueIn);
        this.wavesurfer.play();
    },

    /** Plays the last seconds before cue out and stops there, to check the ending. */
    previewEnding() {
        if (! this.wavesurfer) {
            return;
        }
        this.wavesurfer.setTime(Math.max(this.cues.cueIn, this.cues.cueOut - 8));
        this.stopAtCueOut = true;
        this.wavesurfer.play();
    },

    applyZoom() {
        this.wavesurfer?.zoom(this.zoom);
    },

    formatTime(seconds) {
        const tenths = Math.round(Math.max(0, seconds || 0) * 10);
        const minutes = Math.floor(tenths / 600);
        const rest = ((tenths % 600) / 10).toFixed(1).padStart(4, '0');

        return `${minutes}:${rest}`;
    },

    destroy() {
        this.destroyed = true;
        window.removeEventListener('waveform-set-at-playhead', this.onSetAtPlayhead);
        this.wavesurfer?.destroy();
        this.wavesurfer = null;
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
