@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alert alert-success py-2 small']) }}>
        {{ $status }}
    </div>
@endif
