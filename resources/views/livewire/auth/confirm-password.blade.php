<x-layouts::auth :title="__('Confirm password')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <x-auth-header
                :title="__('Confirm password')"
                :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
            />

            <x-auth-session-status :status="session('status')" />

            <form method="POST" action="{{ route('password.confirm.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="password" class="form-label">{{ __('Password') }}</label>
                    <input id="password" type="password" name="password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="current-password" placeholder="{{ __('Password') }}">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100" data-test="confirm-password-button">
                    {{ __('Confirm') }}
                </button>
            </form>
        </div>
    </div>
</x-layouts::auth>
