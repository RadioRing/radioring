<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body>
        <div class="auth-wrapper p-3">
            <div class="auth-card">
                <div class="text-center mb-4">
                    <a href="{{ route('home') }}" class="text-decoration-none text-dark fw-semibold fs-5" wire:navigate>
                        <i class="bi bi-broadcast-pin me-1 text-primary"></i>
                        {{ config('app.name', 'RadioRing') }}
                    </a>
                </div>

                {{ $slot }}
            </div>
        </div>

        {{-- Toast notifications --}}
        <div
            x-data="{ show: false, message: '', type: 'success' }"
            @notify.window="show = true; message = $event.detail.message; type = $event.detail.type || 'success'; setTimeout(() => show = false, 3500)"
            class="position-fixed top-0 end-0 p-3"
            style="z-index: 9999"
        >
            <div x-show="show" x-transition class="toast show">
                <div class="toast-body d-flex align-items-center gap-2">
                    <i class="bi" :class="type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-info-circle-fill text-primary'"></i>
                    <span x-text="message"></span>
                </div>
            </div>
        </div>
    </body>
</html>
