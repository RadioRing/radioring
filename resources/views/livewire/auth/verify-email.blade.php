<x-layouts::auth :title="__('Email verification')">
    <div class="card shadow-sm auth-card">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="bi bi-envelope-check fs-1 text-primary"></i>
                <h4 class="fw-semibold mt-2">{{ __('Verify your email') }}</h4>
            </div>

            <p class="text-muted-sm text-center">
                {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
            </p>

            @if (session('status') == 'verification-link-sent')
                <div class="alert alert-success small">
                    {{ __('A new verification link has been sent to the email address you provided during registration.') }}
                </div>
            @endif

            <div class="d-grid gap-2 mt-3">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary w-100">
                        {{ __('Resend verification email') }}
                    </button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary w-100" data-test="logout-button">
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-layouts::auth>
