<div>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h4 class="fw-semibold mb-0">
            <i class="bi bi-question-circle me-1 text-primary"></i>{{ __('Hilfe & Handbuch') }}
        </h4>
    </div>

    <div class="card">
        <div class="card-body">
            <article class="handbook">
                {!! $content !!}
            </article>
        </div>
    </div>

    <style>
        .handbook { max-width: 60rem; }
        .handbook h1 { font-size: 1.75rem; font-weight: 600; margin-bottom: 1rem; }
        .handbook h2 { font-size: 1.35rem; font-weight: 600; margin-top: 2.25rem; padding-top: .75rem; border-top: 1px solid var(--bs-border-color); }
        .handbook h3 { font-size: 1.1rem; font-weight: 600; margin-top: 1.5rem; }
        .handbook h1[id], .handbook h2[id], .handbook h3[id] { scroll-margin-top: 1rem; }
        .handbook table { width: 100%; margin: 1rem 0; border-collapse: collapse; }
        .handbook th, .handbook td { border: 1px solid var(--bs-border-color); padding: .5rem .75rem; vertical-align: top; }
        .handbook th { background: var(--bs-tertiary-bg, #f8f9fa); text-align: left; }
        .handbook code { background: var(--bs-tertiary-bg, #f8f9fa); padding: .1rem .3rem; border-radius: .25rem; font-size: .875em; }
        .handbook pre { background: var(--bs-tertiary-bg, #f8f9fa); padding: 1rem; border-radius: .375rem; overflow-x: auto; }
        .handbook pre code { background: none; padding: 0; }
        .handbook blockquote { border-left: 4px solid var(--bs-primary); padding: .25rem 0 .25rem 1rem; margin: 1rem 0; color: var(--bs-secondary-color, #6c757d); }
        .handbook ul, .handbook ol { padding-left: 1.5rem; }
        .handbook li { margin-bottom: .25rem; }
    </style>
</div>