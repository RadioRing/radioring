<x-layouts::auth :title="__('Two-factor authentication')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4"
             x-cloak
             x-data="{
                 showRecoveryInput: @js($errors->has('recovery_code')),
                 toggleInput() {
                     this.showRecoveryInput = !this.showRecoveryInput;
                 },
             }">

            <div x-show="!showRecoveryInput">
                <x-auth-header
                    :title="__('Authentication code')"
                    :description="__('Enter the authentication code provided by your authenticator application.')"
                />
            </div>
            <div x-show="showRecoveryInput">
                <x-auth-header
                    :title="__('Recovery code')"
                    :description="__('Please confirm access to your account by entering one of your emergency recovery codes.')"
                />
            </div>

            <form method="POST" action="{{ route('two-factor.login.store') }}">
                @csrf

                <div x-show="!showRecoveryInput" class="mb-3">
                    <label for="code" class="form-label">{{ __('Code') }}</label>
                    <input id="code" type="text" name="code" inputmode="numeric"
                           class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                           autocomplete="one-time-code" maxlength="6" placeholder="000000">
                    @error('code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div x-show="showRecoveryInput" class="mb-3">
                    <label for="recovery_code" class="form-label">{{ __('Recovery code') }}</label>
                    <input id="recovery_code" type="text" name="recovery_code"
                           class="form-control @error('recovery_code') is-invalid @enderror"
                           autocomplete="one-time-code">
                    @error('recovery_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    {{ __('Continue') }}
                </button>
            </form>

            <p class="text-center text-muted-sm mt-3 mb-0">
                <span x-show="!showRecoveryInput">
                    <a href="#" @click.prevent="toggleInput()">{{ __('Use a recovery code') }}</a>
                </span>
                <span x-show="showRecoveryInput">
                    <a href="#" @click.prevent="toggleInput()">{{ __('Use an authentication code') }}</a>
                </span>
            </p>
        </div>
    </div>
</x-layouts::auth>
