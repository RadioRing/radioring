<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'RadioRing') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-light">
        <div class="auth-wrapper">
            <div class="text-center">
                <i class="bi bi-broadcast-pin display-1 text-primary"></i>
                <h1 class="fw-bold mt-3">{{ config('app.name', 'RadioRing') }}</h1>
                <p class="text-muted mb-4">{{ __('Cloud-based radio automation') }}</p>
                <div class="d-flex gap-2 justify-content-center">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn btn-primary">{{ __('Dashboard') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary">{{ __('Log in') }}</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-outline-secondary">{{ __('Register') }}</a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </body>
</html>
