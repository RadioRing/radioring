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
            <i class="bi bi-envelope me-1"></i>{{ __('Outgoing mail') }}
        </div>
        <div class="card-body">
            <form wire:submit="saveMail">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="mailEnabled" wire:model.live="mailEnabled">
                    <label class="form-check-label fw-medium" for="mailEnabled">{{ __('Use this mail server') }}</label>
                    <div class="text-muted-sm">
                        {{ __('Replaces the MAIL_* settings from the environment. While it is off, the environment stays in charge.') }}
                    </div>
                </div>

                @if($mailEnabled)
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label for="mailHost" class="form-label fw-medium">{{ __('SMTP server') }}</label>
                            <input id="mailHost" type="text" wire:model="mailHost" placeholder="smtp.example.com"
                                   class="form-control @error('mailHost') is-invalid @enderror">
                            @error('mailHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="mailPort" class="form-label fw-medium">{{ __('Port') }}</label>
                            <input id="mailPort" type="number" wire:model="mailPort"
                                   class="form-control @error('mailPort') is-invalid @enderror">
                            @error('mailPort') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-6 col-md-4">
                            <label for="mailEncryption" class="form-label fw-medium">{{ __('Encryption') }}</label>
                            <select id="mailEncryption" wire:model="mailEncryption"
                                    class="form-select @error('mailEncryption') is-invalid @enderror">
                                <option value="starttls">{{ __('STARTTLS (port 587)') }}</option>
                                <option value="tls">{{ __('SSL/TLS (port 465)') }}</option>
                            </select>
                            @error('mailEncryption') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="mailUsername" class="form-label fw-medium">{{ __('Username') }}</label>
                            <input id="mailUsername" type="text" wire:model="mailUsername" autocomplete="off"
                                   class="form-control @error('mailUsername') is-invalid @enderror">
                            @error('mailUsername') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="mailPassword" class="form-label fw-medium">{{ __('Password') }}</label>
                            <input id="mailPassword" type="password" wire:model="mailPassword" autocomplete="new-password"
                                   placeholder="{{ $this->mailHasPassword() ? __('stored, leave empty to keep') : '' }}"
                                   class="form-control @error('mailPassword') is-invalid @enderror">
                            @error('mailPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            @if($this->mailHasPassword())
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" id="mailForgetPassword" wire:model="mailForgetPassword">
                                    <label class="form-check-label text-muted-sm" for="mailForgetPassword">{{ __('Remove the stored password') }}</label>
                                </div>
                            @endif
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="mailFromAddress" class="form-label fw-medium">{{ __('Sender address') }}</label>
                            <input id="mailFromAddress" type="email" wire:model.live.debounce.500ms="mailFromAddress" placeholder="radio@example.com"
                                   class="form-control @error('mailFromAddress') is-invalid @enderror">
                            @error('mailFromAddress') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="mailFromName" class="form-label fw-medium">{{ __('Sender name') }}</label>
                            <input id="mailFromName" type="text" wire:model="mailFromName" placeholder="RadioRing"
                                   class="form-control @error('mailFromName') is-invalid @enderror">
                            @error('mailFromName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                @endif

                <hr>

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="mailStationSender" wire:model.live="mailStationSender">
                    <label class="form-check-label fw-medium" for="mailStationSender">{{ __('Send station mails with their own sender') }}</label>
                    <div class="text-muted-sm">
                        {{ __('Alerts about a station come from «slug»-noreply at the domain below, under the name of the station. This only works if your mail server may send for every address on that domain, and the domain has SPF and DKIM set up.') }}
                    </div>
                </div>

                @if($mailStationSender)
                    <div class="mb-3">
                        <label for="mailStationSenderDomain" class="form-label fw-medium">{{ __('Sender domain') }}</label>
                        <input id="mailStationSenderDomain" type="text" wire:model.live.debounce.500ms="mailStationSenderDomain" placeholder="example.com"
                               class="form-control @error('mailStationSenderDomain') is-invalid @enderror">
                        @error('mailStationSenderDomain') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="text-muted-sm mt-1">
                            {{ __('Leave empty to use the domain of the sender address.') }}
                            @if($this->stationSenderExample())
                                {{ __('Example: :address', ['address' => $this->stationSenderExample()]) }}
                            @endif
                        </div>
                    </div>
                @endif

                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg me-1"></i>{{ __('Save') }}
                </button>
            </form>

            <hr>

            <label for="mailTestRecipient" class="form-label fw-medium">{{ __('Send a test mail') }}</label>
            <div class="d-flex gap-2">
                <input id="mailTestRecipient" type="email" wire:model="mailTestRecipient"
                       class="form-control @error('mailTestRecipient') is-invalid @enderror">
                <button class="btn btn-outline-secondary btn-sm text-nowrap" wire:click="sendTestMail"
                        wire:loading.attr="disabled" wire:target="sendTestMail">
                    <span wire:loading.remove wire:target="sendTestMail"><i class="bi bi-send me-1"></i>{{ __('Send') }}</span>
                    <span wire:loading wire:target="sendTestMail"><span class="spinner-border spinner-border-sm me-1"></span>{{ __('Sending...') }}</span>
                </button>
            </div>
            @error('mailTestRecipient') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
            <div class="text-muted-sm mt-1">{{ __('Uses the values in the form above, even before they are saved.') }}</div>
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

    @if($this->telemetryAvailable())
        <div class="card mt-4" style="max-width: 720px;">
            <div class="card-header fw-medium">
                <i class="bi bi-bar-chart me-1"></i>{{ __('Anonymous usage statistics') }}
            </div>
            <div class="card-body">
                <p class="text-muted-sm">
                    {{ __('Helps us decide what to develop next. Once a day this instance sends size ranges and yes/no details per station to radioring.de, for example how many media files and playlists there are, whether laut.fm or an external Icecast is used and whether Syndications4Radio is connected.') }}
                </p>
                <ul class="text-muted-sm ps-3">
                    <li>{{ __('No names, addresses, URLs, content or credentials.') }}</li>
                    <li>{{ __('Counts only as ranges such as 11-100, never exact numbers.') }}</li>
                    <li>{{ __('The instance ID is random and is deleted when you switch this off.') }}</li>
                    <li>{{ __('radioring.de keeps only the latest report and deletes it after 90 days without a new one.') }}</li>
                </ul>

                @if($this->telemetryEnabled())
                    <div class="alert alert-success py-2">
                        <i class="bi bi-check-circle me-1"></i>{{ __('Switched on.') }}
                        @if($this->telemetryLastSent())
                            {{ __('Last report: :date', ['date' => $this->telemetryLastSent()]) }}
                        @else
                            {{ __('No report has been sent yet.') }}
                        @endif
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-outline-danger btn-sm" wire:click="disableTelemetry">
                            <i class="bi bi-x-lg me-1"></i>{{ __('Switch off') }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" wire:click="resetTelemetryId">
                            <i class="bi bi-arrow-repeat me-1"></i>{{ __('New instance ID') }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" wire:click="$toggle('showTelemetryPreview')">
                            <i class="bi bi-code me-1"></i>{{ __('Show what is sent') }}
                        </button>
                    </div>
                @else
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary btn-sm" wire:click="enableTelemetry"
                                wire:loading.attr="disabled" wire:target="enableTelemetry">
                            <i class="bi bi-check-lg me-1"></i>{{ __('Switch on') }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" wire:click="$toggle('showTelemetryPreview')">
                            <i class="bi bi-code me-1"></i>{{ __('Show what would be sent') }}
                        </button>
                    </div>
                @endif

                @if($showTelemetryPreview)
                    <pre class="bg-body-tertiary border rounded p-2 mt-3 mb-0 small" style="max-height: 360px; overflow: auto;">{{ $this->telemetryPreview() }}</pre>
                @endif
            </div>
        </div>
    @endif
</div>
