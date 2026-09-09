<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-sliders me-2 text-primary"></i>{{ __('Instance settings') }}
        </h4>
    </div>

    <div class="card" style="max-width: 720px;">
        <div class="card-header fw-medium">
            <i class="bi bi-diagram-3 me-1"></i>{{ __('Operating mode') }}
        </div>
        <div class="card-body">
            <p class="text-muted-sm">
                {{ __('Applies immediately, without a redeployment. Switching never changes existing data.') }}
            </p>

            {{-- Loop variable deliberately NOT named $mode: that is the bound property. --}}
            @foreach($modes as $option)
                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" wire:model.live="mode"
                           value="{{ $option->value }}" id="mode-{{ $option->value }}">
                    <label class="form-check-label" for="mode-{{ $option->value }}">
                        <span class="fw-medium">{{ $option->label() }}</span>
                        @if($option === $currentMode)
                            <span class="badge text-bg-success-subtle text-success ms-1">{{ __('active') }}</span>
                        @endif
                        <span class="d-block text-muted-sm">{{ $option->description() }}</span>
                    </label>
                </div>
            @endforeach

            @error('mode') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

            {{-- Warnung, wenn Standalone bei mehreren Mandanten gewaehlt wird. --}}
            @if($mode === 'standalone' && $this->switchingToStandaloneIsAmbiguous())
                <div class="alert alert-warning">
                    <p class="fw-medium mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>{{ __('There are :n tenants on this instance.', ['n' => $this->tenantCount()]) }}
                    </p>
                    <ul class="mb-0 ps-3 small">
                        <li>{{ __('Existing media libraries stay separate and unchanged.') }}</li>
                        <li>{{ __('New registrations will join «:name» from now on.', ['name' => $this->standaloneTenantName()]) }}</li>
                        <li>{{ __('Station quota, impersonation and account bans will be hidden.') }}</li>
                    </ul>
                </div>
            @endif

            <button class="btn btn-primary btn-sm"
                    wire:click="save"
                    @disabled($mode === $currentMode->value)>
                <i class="bi bi-check-lg me-1"></i>{{ __('Apply') }}
            </button>
        </div>
    </div>

    <div class="card mt-4" style="max-width: 720px;">
        <div class="card-header fw-medium">
            <i class="bi bi-sliders me-1"></i>{{ __('Stereo Tool licence') }}
        </div>
        <div class="card-body">
            <p class="text-muted-sm">
                {{ __('Stereo Tool, its presets and its shared library are proprietary software by Thimeo and are not covered by the RadioRing licence. They ship inside the station image with permission from Thimeo.') }}
                <a href="{{ $stereoToolLicenceUrl }}" target="_blank" rel="noopener noreferrer">{{ __('Read the licence') }}</a>
            </p>

            <ul class="text-muted-sm ps-3">
                <li>{{ __('Each station needs its own Thimeo licence key: the licence is per stream.') }}</li>
                <li>{{ __('Without a valid key Stereo Tool runs in demo mode and mixes noise into the audio at intervals.') }}</li>
                <li>{{ __('If you redistribute a modified RadioRing image yourself, you need your own permission from Thimeo.') }}</li>
            </ul>

            @if($this->stereoToolTermsAccepted())
                <div class="alert alert-success py-2">
                    <i class="bi bi-check-circle me-1"></i>{{ $this->stereoToolAcceptance() }}
                </div>

                <button class="btn btn-outline-danger btn-sm"
                        @click="$dispatch('confirm-dialog', { message: @js(__('Withdraw acceptance? Stereo Tool will be disabled on every station immediately. Licence keys and presets are kept.')), confirmText: @js(__('Withdraw')), confirmClass: 'btn-danger', onConfirm: () => $wire.revokeStereoToolTerms() })">
                    <i class="bi bi-x-lg me-1"></i>{{ __('Withdraw acceptance') }}
                </button>
            @else
                <div class="form-check mb-3">
                    <input class="form-check-input @error('stereoToolTermsAgreed') is-invalid @enderror"
                           type="checkbox" wire:model="stereoToolTermsAgreed" id="stereoToolTermsAgreed">
                    <label class="form-check-label" for="stereoToolTermsAgreed">
                        {{ __('I accept the Thimeo licence for Stereo Tool, its presets and its shared library.') }}
                    </label>
                    @error('stereoToolTermsAgreed')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <button class="btn btn-primary btn-sm" wire:click="acceptStereoToolTerms">
                    <i class="bi bi-check-lg me-1"></i>{{ __('Accept') }}
                </button>
                <span class="d-block text-muted-sm mt-2">
                    {{ __('Until this is accepted, Stereo Tool cannot be enabled for any station.') }}
                </span>
            @endif
        </div>
    </div>
</div>
