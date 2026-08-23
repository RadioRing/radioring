<x-layouts::auth :title="__('Register')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

            <x-auth-session-status :status="session('status')" />

            <form method="POST" action="{{ route('register.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="name" class="form-label">{{ __('Name') }}</label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}"
                           class="form-control @error('name') is-invalid @enderror"
                           required autofocus autocomplete="name" placeholder="{{ __('Full name') }}">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">{{ __('Email address') }}</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autocomplete="email" placeholder="email@example.com">
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

                <div class="mb-3">
                    <label for="invite_code" class="form-label">{{ __('Invite code') }}</label>
                    <input id="invite_code" type="text" name="invite_code" value="{{ old('invite_code') }}"
                           class="form-control @error('invite_code') is-invalid @enderror"
                           required autocomplete="off" placeholder="XXXX-XXXX-XXXX">
                    @error('invite_code')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary w-100" data-test="register-user-button">
                    {{ __('Create account') }}
                </button>
            </form>

            <p class="text-center text-muted-sm mt-3 mb-0">
                {{ __('Already have an account?') }}
                <a href="{{ route('login') }}" wire:navigate>{{ __('Log in') }}</a>
            </p>
        </div>
    </div>
</x-layouts::auth>
