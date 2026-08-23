<x-layouts::auth :title="__('Forgot password')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

            <x-auth-session-status :status="session('status')" />

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('Email address') }}</label>
                    <input id="email" type="email" name="email"
                           class="form-control @error('email') is-invalid @enderror"
                           required autofocus placeholder="email@example.com">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100" data-test="email-password-reset-link-button">
                    {{ __('Email password reset link') }}
                </button>
            </form>

            <p class="text-center text-muted-sm mt-3 mb-0">
                {{ __('Or, return to') }}
                <a href="{{ route('login') }}" wire:navigate>{{ __('log in') }}</a>
            </p>
        </div>
    </div>
</x-layouts::auth>
