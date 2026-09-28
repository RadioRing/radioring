<x-mail::message>
# {{ __('Mail delivery works') }}

{{ __('This test mail was sent from the instance settings of :app. Alerts about your stations will reach you the same way.', ['app' => config('app.name')]) }}
</x-mail::message>
