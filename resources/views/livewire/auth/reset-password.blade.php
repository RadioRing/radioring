<x-layouts::auth :title="__('Reset password')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

            <x-auth-session-status :status="session('status')" />

            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ request()->route('token') }}">

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('Email') }}</label>
                    <input id="email" type="email" name="email" value="{{ request('email') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autocomplete="email">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">{{ __('Password') }}</label>
                    <input id="password" type="password" name="password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="new-password" placeholder="{{ __('Password') }}">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">{{ __('Confirm password') }}</label>
                    <input id="password_confirmation" type="password" name="password_confirmation"
                           class="form-control"
                           required autocomplete="new-password" placeholder="{{ __('Confirm password') }}">
                </div>

                <button type="submit" class="btn btn-primary w-100" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </button>
            </form>
        </div>
    </div>
</x-layouts::auth>
