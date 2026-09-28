<x-mail::message>
@if($resolved)
# {{ __('Resolved: :problem', ['problem' => $problem]) }}

{{ __('The problem at :station has cleared.', ['station' => $station->name]) }}

- {{ __('Started: :time', ['time' => $startedAt->isoFormat('LLL')]) }}
- {{ __('Resolved: :time', ['time' => $resolvedAt?->isoFormat('LLL')]) }}
@else
# {{ $problem }}

{{ __('Station: :station', ['station' => $station->name]) }}

{{ $explanation }}

- {{ __('Since: :time', ['time' => $startedAt->isoFormat('LLL')]) }}

{{ __('You will get one more mail once the problem has cleared.') }}
@endif

<x-mail::button :url="route('dashboard')">
{{ __('Open the dashboard') }}
</x-mail::button>

<x-mail::subcopy>
{{ __('You receive this mail because you are an owner of :station. Alert mails can be turned off for yourself in your profile, or for the whole station in its settings.', ['station' => $station->name]) }}
</x-mail::subcopy>
</x-mail::message>
