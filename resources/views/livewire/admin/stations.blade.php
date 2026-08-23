<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-broadcast-pin me-2 text-primary"></i>{{ __('Stationen') }}
        </h4>
    </div>

    <div class="mb-3" style="max-width:360px">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" wire:model.live.debounce.300ms="search"
                   class="form-control" placeholder="{{ __('Name oder Slug ...') }}">
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Besitzer') }}</th>
                        <th>{{ __('Stereo Tool') }}</th>
                        <th class="text-end">{{ __('Aktionen') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stations as $station)
                        <tr wire:key="station-{{ $station->id }}">
                            <td class="fw-medium">
                                {{ $station->name }}
                                <div class="text-muted-sm"><code>{{ $station->slug }}</code></div>
                            </td>
                            <td class="text-muted-sm">{{ $station->owner?->email ?? '–' }}</td>
                            <td>
                                @if($station->stereo_tool_enabled)
                                    @if($station->stereoToolActive())
                                        <span class="badge text-bg-success">{{ __('Aktiv') }}</span>
                                    @else
                                        <span class="badge text-bg-warning">{{ __('Freigeschaltet – nicht konfiguriert') }}</span>
                                    @endif
                                @else
                                    <span class="badge text-bg-light border text-muted">{{ __('Nicht freigeschaltet') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm {{ $station->stereo_tool_enabled ? 'btn-outline-danger' : 'btn-outline-primary' }}"
                                        @click="$dispatch('confirm-dialog', { message: @js($station->stereo_tool_enabled
                                            ? __('Stereo Tool für „:name" deaktivieren?', ['name' => $station->name])
                                            : __('Stereo Tool für „:name" freischalten? Das erhöht die CPU-Last des Containers dauerhaft.', ['name' => $station->name])), confirmClass: @js($station->stereo_tool_enabled ? 'btn-danger' : 'btn-primary'), onConfirm: () => $wire.toggleStereoTool({{ $station->id }}) })">
                                    <i class="bi {{ $station->stereo_tool_enabled ? 'bi-toggle-on' : 'bi-toggle-off' }} me-1"></i>
                                    {{ $station->stereo_tool_enabled ? __('Deaktivieren') : __('Freischalten') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">{{ __('Keine Stationen gefunden.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $stations->links() }}
    </div>
</div>
