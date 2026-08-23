@props(['heading', 'subheading' => ''])

<div class="row">
    <div class="col-md-3 mb-3 mb-md-0">
        <nav class="nav flex-column nav-pills">
            <a class="nav-link {{ request()->routeIs('profile.edit') ? 'active' : 'text-body' }}"
               href="{{ route('profile.edit') }}" wire:navigate>
                {{ __('Profile') }}
            </a>
            <a class="nav-link {{ request()->routeIs('security.edit') ? 'active' : 'text-body' }}"
               href="{{ route('security.edit') }}" wire:navigate>
                {{ __('Security') }}
            </a>
        </nav>
    </div>
    <div class="col-md-9">
        <h5 class="fw-semibold mb-1">{{ $heading }}</h5>
        @if($subheading)
            <p class="text-muted-sm mb-4">{{ $subheading }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
