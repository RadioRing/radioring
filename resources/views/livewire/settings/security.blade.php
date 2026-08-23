<section class="w-100">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">

        <form wire:submit="updatePassword" class="mt-3">
            <div class="mb-3">
                <label for="current_password" class="form-label">{{ __('Current password') }}</label>
                <input id="current_password" type="password" wire:model="current_password"
                       class="form-control @error('current_password') is-invalid @enderror"
                       required autocomplete="current-password">
                @error('current_password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="new_password" class="form-label">{{ __('New password') }}</label>
                <input id="new_password" type="password" wire:model="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required autocomplete="new-password">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="form-label">{{ __('Confirm password') }}</label>
                <input id="password_confirmation" type="password" wire:model="password_confirmation"
                       class="form-control"
                       required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary" data-test="update-password-button">
                {{ __('Save') }}
            </button>
        </form>

        @if ($canManageTwoFactor)
            <section class="mt-5 pt-4 border-top">
                <h5 class="fw-semibold">{{ __('Two-factor authentication') }}</h5>
                <p class="text-muted-sm">{{ __('Manage your two-factor authentication settings') }}</p>

                <div wire:cloak>
                    @if ($twoFactorEnabled)
                        <p class="text-muted-sm mb-3">
                            {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                        </p>
                        <button class="btn btn-danger" wire:click="disable">
                            {{ __('Disable 2FA') }}
                        </button>

                        <livewire:settings.two-factor.recovery-codes :$requiresConfirmation/>
                    @else
                        <p class="text-muted-sm mb-3">
                            {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login.') }}
                        </p>
                        <button class="btn btn-primary" wire:click="enable">
                            {{ __('Enable 2FA') }}
                        </button>
                    @endif
                </div>
            </section>

            {{-- 2FA Setup Modal – serverseitig über Livewire ($showModal) gesteuert,
                 wie die übrigen Modals der App (kein Alpine). --}}
            @if ($showModal)
                <div class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.5);">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ $this->modalConfig['title'] }}</h5>
                                <button type="button" class="btn-close" wire:click="closeModal"></button>
                            </div>
                            <div class="modal-body">
                                @if (! $showVerificationStep)
                                    <p class="text-muted-sm">{{ __('To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app.') }}</p>
                                @else
                                    <p class="text-muted-sm">{{ __('Enter the 6-digit code from your authenticator app.') }}</p>
                                @endif

                                @error('setupData')
                                    <div class="alert alert-danger small">{{ $message }}</div>
                                @enderror

                                @if (! $showVerificationStep)
                                    {{-- QR-Code --}}
                                    @empty($qrCodeSvg)
                                        <div class="d-flex justify-content-center align-items-center py-4">
                                            <div class="spinner-border text-primary" role="status"></div>
                                        </div>
                                    @else
                                        <div class="d-flex justify-content-center mb-3">
                                            <div class="border rounded p-2 bg-white" style="width:200px;height:200px;">
                                                {!! $qrCodeSvg !!}
                                            </div>
                                        </div>
                                    @endempty

                                    @if (filled($manualSetupKey))
                                        <div class="mb-3">
                                            <label class="form-label small text-muted">{{ __('Or enter the code manually') }}</label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control font-monospace"
                                                       value="{{ $manualSetupKey }}" readonly>
                                                <button class="btn btn-outline-secondary" type="button"
                                                        onclick="navigator.clipboard.writeText('{{ $manualSetupKey }}')">
                                                    <i class="bi bi-clipboard"></i>
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    {{-- Verifizierungs-Schritt --}}
                                    <div class="mb-3">
                                        <label for="otp_code" class="form-label">{{ __('Authentication code') }}</label>
                                        <input id="otp_code" type="text" wire:model="code"
                                               class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror"
                                               inputmode="numeric" maxlength="6" autocomplete="one-time-code"
                                               placeholder="000000">
                                        @error('code')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </div>
                                @endif
                            </div>
                            <div class="modal-footer">
                                @if ($showVerificationStep)
                                    <button class="btn btn-secondary" wire:click="resetVerification">
                                        {{ __('Back') }}
                                    </button>
                                    <button class="btn btn-primary" wire:click="confirmTwoFactor">
                                        {{ __('Confirm') }}
                                    </button>
                                @else
                                    <button class="btn btn-primary" wire:click="showVerificationIfNecessary"
                                            @error('setupData') disabled @enderror>
                                        {{ $this->modalConfig['buttonText'] }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endif

    </x-settings.layout>
</section>
