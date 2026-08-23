<div x-data="{
    bulkMode: false,
    selected: [],
    bulkPlaylistId: '',
    isSelected(weekday, hour) {
        return this.selected.some(c => c.weekday === weekday && c.hour === hour);
    },
    toggleCell(weekday, hour) {
        if (this.isSelected(weekday, hour)) {
            this.selected = this.selected.filter(c => !(c.weekday === weekday && c.hour === hour));
        } else {
            this.selected.push({ weekday, hour });
        }
    },
    assignSelected() {
        if (!this.bulkPlaylistId || this.selected.length === 0) return;
        $wire.assignMultiple(this.selected, parseInt(this.bulkPlaylistId));
        this.selected = [];
        this.bulkPlaylistId = '';
        this.bulkMode = false;
    },
    clearSelected() {
        if (this.selected.length === 0) return;
        $wire.clearMultiple(this.selected);
        this.selected = [];
        this.bulkMode = false;
    }
}">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-grid-3x3-gap me-2 text-primary"></i>{{ __('Wochenraster') }}
        </h4>
        <div class="d-flex gap-2 align-items-center">
            <a href="{{ route('rundown.show', [today()->toDateString(), now()->hour]) }}"
               class="btn btn-sm btn-outline-secondary" wire:navigate>
                <i class="bi bi-clock-history me-1"></i>{{ __('Rundown heute') }}
            </a>
            <button class="btn btn-sm btn-outline-success" wire:click="openGeneratePanel">
                <i class="bi bi-lightning-charge me-1"></i>{{ __('Rundowns generieren') }}
            </button>
            <button class="btn btn-sm"
                    :class="bulkMode ? 'btn-warning' : 'btn-outline-secondary'"
                    @click="bulkMode = !bulkMode; selected = []">
                <i class="bi bi-ui-checks-grid me-1"></i>
                <span x-text="bulkMode ? '{{ __('Auswahl beenden') }}' : '{{ __('Mehrfachzuweisung') }}'"></span>
            </button>
        </div>
    </div>

    {{-- Rundown-Generator-Panel --}}
    @if($showGeneratePanel)
        <div class="card mb-3 border-success">
            <div class="card-header d-flex align-items-center justify-content-between bg-success bg-opacity-10">
                <span class="fw-medium">
                    <i class="bi bi-lightning-charge me-1 text-success"></i>{{ __('Rundowns generieren') }}
                </span>
                <button type="button" class="btn-close" wire:click="closeGeneratePanel"></button>
            </div>
            <div class="card-body">

                @if(empty($generateLog))
                    {{-- Tag-Auswahl --}}
                    <p class="text-muted small mb-3">{{ __('Wähle die Tage, für die alle konfigurierten Slots generiert werden sollen:') }}</p>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        {{-- Heute-Shortcut --}}
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                wire:click="$set('generateWeekdays', [{{ today()->dayOfWeekIso - 1 }}])">
                            <i class="bi bi-calendar-check me-1"></i>{{ __('Nur heute') }}
                        </button>
                        {{-- Alle auswählen --}}
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$set('generateWeekdays', [0,1,2,3,4,5,6])">
                            <i class="bi bi-check-all me-1"></i>{{ __('Alle 7 Tage') }}
                        </button>
                        {{-- Alle abwählen --}}
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                wire:click="$set('generateWeekdays', [])">
                            <i class="bi bi-x-lg me-1"></i>{{ __('Keine') }}
                        </button>
                    </div>

                    <div class="row g-2 mb-3">
                        @foreach($weekdays as $d => $label)
                            @php
                                $date = \Carbon\Carbon::parse($weekDates[$d]);
                                $isToday = $date->isToday();
                                $slots = $slotCountPerWeekday[$d];
                            @endphp
                            <div class="col-6 col-sm-4 col-md-3 col-lg-auto">
                                <div class="form-check border rounded px-3 py-2 {{ $isToday ? 'border-primary bg-primary bg-opacity-5' : '' }}">
                                    <input class="form-check-input" type="checkbox"
                                           id="gen-day-{{ $d }}"
                                           value="{{ $d }}"
                                           wire:model.live="generateWeekdays">
                                    <label class="form-check-label" for="gen-day-{{ $d }}">
                                        <span class="fw-medium">{{ $label }}</span>
                                        <span class="text-muted small ms-1">{{ $date->format('d.m.') }}</span>
                                        @if($isToday)
                                            <span class="badge bg-primary ms-1" style="font-size:.6rem">Heute</span>
                                        @endif
                                        <br>
                                        <span class="text-muted" style="font-size:.75rem">
                                            {{ $slots }} {{ $slots === 1 ? 'Slot' : 'Slots' }}
                                        </span>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-success btn-sm"
                                @click="$dispatch('confirm-dialog', { message: @js(__('Bestehende Rundowns dieser Tage werden überschrieben. Läuft gerade ein Rundown live, wird er neu erzeugt – der aktuell laufende Track spielt zu Ende, danach greift die neue Playlist. Fortfahren?')), confirmText: @js(__('Generieren')), confirmClass: 'btn-success', onConfirm: () => $wire.generateSelected() })"
                                wire:loading.attr="disabled"
                                @disabled(empty($generateWeekdays))>
                            <span wire:loading wire:target="generateSelected" class="spinner-border spinner-border-sm me-1"></span>
                            <i wire:loading.remove wire:target="generateSelected" class="bi bi-lightning-charge me-1"></i>
                            {{ __('Generieren') }}
                            @if(! empty($generateWeekdays))
                                ({{ count($generateWeekdays) }} {{ count($generateWeekdays) === 1 ? 'Tag' : 'Tage' }})
                            @endif
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" wire:click="closeGeneratePanel">
                            {{ __('Abbrechen') }}
                        </button>
                    </div>

                @else
                    {{-- Ergebnis-Log --}}
                    <p class="fw-medium mb-2">{{ __('Ergebnis:') }}</p>
                    <ul class="list-unstyled small mb-3">
                        @foreach($generateLog as $entry)
                            <li class="mb-1">
                                @if($entry['status'] === 'ok')
                                    <i class="bi bi-check-circle-fill text-success me-1"></i>
                                @elseif($entry['status'] === 'error')
                                    <i class="bi bi-x-circle-fill text-danger me-1"></i>
                                @else
                                    <i class="bi bi-dash-circle text-muted me-1"></i>
                                @endif
                                {{ $entry['text'] }}
                            </li>
                        @endforeach
                    </ul>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-success" wire:click="$set('generateLog', [])">
                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('Nochmal generieren') }}
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" wire:click="closeGeneratePanel">
                            {{ __('Schließen') }}
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Bulk-Aktionsleiste --}}
    <div x-show="bulkMode && selected.length > 0"
         x-transition
         class="card mb-3 border-warning">
        <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
            <span class="text-muted small">
                <i class="bi bi-check2-square me-1"></i>
                <span x-text="selected.length"></span> {{ __('Slot(s) ausgewählt') }}
            </span>
            <select x-model="bulkPlaylistId" class="form-select form-select-sm" style="max-width:220px">
                <option value="">{{ __('Playlist wählen...') }}</option>
                @foreach($playlists as $pl)
                    <option value="{{ $pl->id }}">{{ $pl->name }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-primary" @click="assignSelected" :disabled="!bulkPlaylistId">
                <i class="bi bi-check-lg me-1"></i>{{ __('Zuweisen') }}
            </button>
            <button class="btn btn-sm btn-outline-danger" @click="clearSelected">
                <i class="bi bi-x-lg me-1"></i>{{ __('Leeren') }}
            </button>
        </div>
    </div>

    {{-- Slot-Editor --}}
    @if($editingWeekday !== null)
        <div class="card mb-3 border-primary">
            <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
                <span class="fw-medium small">
                    {{ $weekdays[$editingWeekday] }} {{ sprintf('%02d:00', $editingHour) }}
                </span>
                <select wire:model="editingPlaylistId" class="form-select form-select-sm" style="max-width:220px">
                    <option value="">{{ __('– Kein Programm –') }}</option>
                    @foreach($playlists as $pl)
                        <option value="{{ $pl->id }}">{{ $pl->name }}{{ $pl->start_mode === 'hard' ? ' ⏰' : '' }}</option>
                    @endforeach
                </select>
                <span class="text-muted small">
                    <i class="bi bi-info-circle me-1"></i>{{ __('Hart/Weich wird an der Playlist eingestellt.') }}
                </span>
                <button class="btn btn-sm btn-primary" wire:click="saveSlot">
                    <i class="bi bi-check-lg me-1"></i>{{ __('Speichern') }}
                </button>
                <button class="btn btn-sm btn-outline-secondary" wire:click="cancelEditing">
                    {{ __('Abbrechen') }}
                </button>
            </div>
        </div>
    @endif

    {{-- Raster-Tabelle --}}
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle mb-0" style="font-size:.82rem">
            <thead class="table-dark">
                <tr>
                    <th style="width:54px">{{ __('Zeit') }}</th>
                    @foreach($weekdays as $d => $label)
                        <th class="text-center {{ \Carbon\Carbon::parse($weekDates[$d])->isToday() ? 'table-primary' : '' }}">
                            {{ $label }}<br>
                            <small class="fw-normal opacity-75">{{ \Carbon\Carbon::parse($weekDates[$d])->format('d.m.') }}</small>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @for($hour = 0; $hour < 24; $hour++)
                    <tr>
                        <td class="text-muted text-nowrap fw-medium">{{ sprintf('%02d:00', $hour) }}</td>
                        @for($day = 0; $day < 7; $day++)
                            @php
                                $slot = $grid[$day][$hour] ?? null;
                                $rundownStatus = $rundowns[$day][$hour] ?? null;
                            @endphp
                            <td class="p-0"
                                :class="{
                                    'table-warning': isSelected({{ $day }}, {{ $hour }}),
                                    'cursor-pointer': true
                                }"
                                style="cursor:pointer; min-width:90px">

                                <div class="px-2 py-1 h-100 w-100"
                                     @click="bulkMode ? toggleCell({{ $day }}, {{ $hour }}) : $wire.editSlot({{ $day }}, {{ $hour }})"
                                     :class="{ 'bg-warning bg-opacity-25': isSelected({{ $day }}, {{ $hour }}) }">

                                    @if($slot)
                                        <div class="text-truncate text-dark fw-medium" style="max-width:110px">
                                            @if($slot->playlist->start_mode === 'hard')
                                                <i class="bi bi-clock-fill text-danger me-1" title="{{ __('Harter Start zur vollen Stunde') }}"></i>
                                            @endif
                                            {{ $slot->playlist->name }}
                                        </div>
                                        @if($rundownStatus)
                                            <span class="badge bg-{{ $rundownStatus === 'ready' ? 'success' : ($rundownStatus === 'played' ? 'secondary' : 'warning text-dark') }}"
                                                  style="font-size:.65rem">
                                                {{ match($rundownStatus) { 'ready' => 'Bereit', 'played' => 'Gespielt', default => 'Entwurf' } }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-muted">–</span>
                                    @endif
                                </div>
                            </td>
                        @endfor
                    </tr>
                @endfor
            </tbody>
        </table>
    </div>

    {{-- Legende --}}
    <div class="mt-3 d-flex gap-3 flex-wrap" style="font-size:.75rem">
        <span class="text-muted"><i class="bi bi-circle-fill text-success me-1"></i>{{ __('Rundown bereit') }}</span>
        <span class="text-muted"><i class="bi bi-circle-fill text-secondary me-1"></i>{{ __('Gespielt') }}</span>
        <span class="text-muted"><i class="bi bi-circle-fill text-warning me-1"></i>{{ __('Entwurf') }}</span>
    </div>
</div>
