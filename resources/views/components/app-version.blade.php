@php($version = \App\Support\AppVersion::fromConfig())

<div class="pt-2 mt-2 border-top border-secondary" style="font-size:.7rem;">
    @if ($version->url())
        <a href="{{ $version->url() }}" target="_blank" rel="noopener noreferrer"
           class="text-muted-sm text-decoration-none d-inline-flex align-items-center gap-1"
           title="{{ $version->isRelease() ? __('Release notes') : __('This build on GitHub') }}">
            <i class="bi {{ $version->isRelease() ? 'bi-tag' : 'bi-git' }}"></i>
            <span>{{ $version->label() }}</span>
        </a>
    @else
        <span class="text-muted-sm d-inline-flex align-items-center gap-1">
            <i class="bi bi-git"></i>
            <span>{{ $version->label() }}</span>
        </span>
    @endif
</div>
