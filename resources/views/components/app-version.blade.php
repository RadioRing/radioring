@php($version = \App\Support\AppVersion::fromConfig())
@php($update = auth()->user()?->isAdmin() ? \App\Services\UpdateChecker::forCurrentBuild()->availableUpdate() : null)

<div class="pt-2 mt-2 border-top border-secondary" style="font-size:.7rem;">
    @if ($update)
        <div x-data>
            <button type="button" class="btn btn-link p-0 text-decoration-none app-version-update"
                    title="{{ __('Update available') }}" data-test="update-badge"
                    x-on:click="$dispatch('update-notes-open')">
                <i class="bi bi-arrow-up-circle-fill"></i>
                <span>{{ $version->label() }}</span>
            </button>

            {{-- Teleported, so the sidebar's stacking context does not trap the dialog. --}}
            <template x-teleport="body">
                <x-modal open-event="update-notes-open" close-event="update-notes-close">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-arrow-up-circle me-1 text-primary"></i>
                            @if ($update['kind'] === 'release')
                                {{ __('New release: :title', ['title' => $update['title']]) }}
                            @else
                                {{ trans_choice(':count new commit on :branch|:count new commits on :branch', $update['behind'], ['branch' => $update['title']]) }}
                            @endif
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            {{ __('Installed: :version', ['version' => $version->label()]) }}
                            @if ($update['published_at'])
                                · {{ __('Published: :date', ['date' => \Illuminate\Support\Carbon::parse($update['published_at'])->isoFormat('LLL')]) }}
                            @endif
                        </p>

                        @if ($update['kind'] === 'release')
                            <div class="release-notes">
                                {!! \Illuminate\Support\Str::markdown($update['notes'] ?: __('No release notes.'), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </div>
                        @else
                            <ul class="list-group list-group-flush small">
                                @foreach ($update['commits'] as $commit)
                                    <li class="list-group-item px-0 d-flex gap-2">
                                        <code class="text-muted">{{ $commit['sha'] }}</code>
                                        <span>{{ $commit['message'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($update['behind'] > count($update['commits']))
                                <p class="text-muted small mt-2 mb-0">
                                    {{ __('... and :count more', ['count' => $update['behind'] - count($update['commits'])]) }}
                                </p>
                            @endif
                        @endif
                    </div>
                    <div class="modal-footer">
                        <a href="{{ $update['url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                            <i class="bi bi-github me-1"></i>{{ __('View on GitHub') }}
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </x-modal>
            </template>
        </div>
    @elseif ($version->url())
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
