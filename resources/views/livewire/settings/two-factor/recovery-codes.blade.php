<div class="card mt-3" wire:cloak x-data="{ showRecoveryCodes: false }">
    <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="bi bi-shield-lock text-muted"></i>
            <h6 class="fw-semibold mb-0">{{ __('2FA recovery codes') }}</h6>
        </div>
        <p class="text-muted-sm mb-3">
            {{ __('Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.') }}
        </p>

        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-primary"
                    x-show="!showRecoveryCodes"
                    @click="showRecoveryCodes = true">
                <i class="bi bi-eye me-1"></i>{{ __('View recovery codes') }}
            </button>

            <button class="btn btn-sm btn-secondary"
                    x-show="showRecoveryCodes"
                    @click="showRecoveryCodes = false">
                <i class="bi bi-eye-slash me-1"></i>{{ __('Hide recovery codes') }}
            </button>

            @if (filled($recoveryCodes))
                <button class="btn btn-sm btn-outline-secondary"
                        x-show="showRecoveryCodes"
                        wire:click="regenerateRecoveryCodes">
                    <i class="bi bi-arrow-repeat me-1"></i>{{ __('Regenerate codes') }}
                </button>
            @endif
        </div>

        <div x-show="showRecoveryCodes" x-transition class="mt-3">
            @error('recoveryCodes')
                <div class="alert alert-danger small">{{ $message }}</div>
            @enderror

            @if (filled($recoveryCodes))
                <div class="bg-light border rounded p-3 font-monospace small"
                     role="list" aria-label="{{ __('Recovery codes') }}">
                    @foreach($recoveryCodes as $code)
                        <div role="listitem" wire:loading.class="opacity-50">{{ $code }}</div>
                    @endforeach
                </div>
                <p class="text-muted-sm mt-2 small">
                    {{ __('Each recovery code can be used once to access your account and will be removed after use.') }}
                </p>
            @endif
        </div>
    </div>
</div>
