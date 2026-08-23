<x-layouts::auth :title="__('Log in')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

            <x-auth-session-status :status="session('status')" />

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('Email address') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autofocus autocomplete="email" placeholder="email@example.com">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="form-label mb-0">{{ __('Password') }}</label>
                        @if (Route::has('password.request'))
                            <a href="{{ route('password.request') }}" class="small" wire:navigate>
                                {{ __('Forgot your password?') }}
                            </a>
                        @endif
                    </div>
                    <input id="password" type="password" name="password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="current-password" placeholder="{{ __('Password') }}">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3 form-check">
                    <input type="checkbox" id="remember" name="remember" class="form-check-input"
                           {{ old('remember') ? 'checked' : '' }}>
                    <label for="remember" class="form-check-label">{{ __('Remember me') }}</label>
                </div>

                <button type="submit" class="btn btn-primary w-100" data-test="login-button">
                    {{ __('Log in') }}
                </button>
            </form>

            @if (Route::has('register'))
                <p class="text-center text-muted-sm mt-3 mb-0">
                    {{ __("Don't have an account?") }}
                    <a href="{{ route('register') }}" wire:navigate>{{ __('Sign up') }}</a>
                </p>
            @endif
        </div>
    </div>
</x-layouts::auth>
