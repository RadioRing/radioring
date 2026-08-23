<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-ticket-perforated me-2 text-primary"></i>{{ __('Einladungscodes') }}
        </h4>
        <a href="{{ route('admin.users') }}" class="btn btn-outline-secondary btn-sm" wire:navigate>
            <i class="bi bi-people me-1"></i>{{ __('Nutzerverwaltung') }}
        </a>
    </div>

    <div class="card mb-4" style="max-width:560px">
        <div class="card-body">
            <h6 class="fw-semibold">{{ __('Neue Codes erzeugen') }}</h6>
            <form wire:submit="generate" class="row g-2 align-items-start">
                <div class="col-sm-3">
                    <label class="form-label small fw-medium mb-1">{{ __('Anzahl') }}</label>
                    <input type="number" wire:model="count" min="1" max="50"
                           class="form-control form-control-sm @error('count') is-invalid @enderror">
                    @error('count') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-medium mb-1">{{ __('Notiz (optional)') }}</label>
                    <input type="text" wire:model="note"
                           class="form-control form-control-sm @error('note') is-invalid @enderror"
                           placeholder="{{ __('z. B. für Max') }}">
                    @error('note') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-sm-3 d-flex align-items-end" style="height:100%">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg me-1"></i>{{ __('Erzeugen') }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>{{ __('Code') }}</th>
                        <th>{{ __('Notiz') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Verwendet von') }}</th>
                        <th class="text-end">{{ __('Aktion') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($codes as $code)
                        <tr wire:key="code-{{ $code->id }}">
                            <td><code>{{ $code->code }}</code></td>
                            <td class="text-muted-sm">{{ $code->note ?? '–' }}</td>
                            <td>
                                @if($code->isUsed())
                                    <span class="badge text-bg-secondary">{{ __('Verbraucht') }}</span>
                                @else
                                    <span class="badge text-bg-success">{{ __('Frei') }}</span>
                                @endif
                            </td>
                            <td class="text-muted-sm">
                                {{ $code->usedBy?->email ?? '–' }}
                                @if($code->used_at)
                                    <span class="d-block text-muted" style="font-size:.7rem">{{ $code->used_at->format('d.m.Y H:i') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @unless($code->isUsed())
                                    <button class="btn btn-sm btn-outline-danger"
                                            @click="$dispatch('confirm-dialog', { message: @js(__('Code löschen?')), confirmText: @js(__('Löschen')), onConfirm: () => $wire.deleteCode({{ $code->id }}) })">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @else
                                    <span class="text-muted-sm">–</span>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">{{ __('Noch keine Codes. Oben welche erzeugen.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
