<section class="w-100">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="mt-3">
            <div class="mb-3">
                <label for="name" class="form-label">{{ __('Name') }}</label>
                <input id="name" type="text" wire:model="name"
                       class="form-control @error('name') is-invalid @enderror"
                       required autofocus autocomplete="name">
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">{{ __('Email') }}</label>
                <input id="email" type="email" wire:model="email"
                       class="form-control @error('email') is-invalid @enderror"
                       required autocomplete="email">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror

                @if ($this->hasUnverifiedEmail)
                    <div class="form-text text-warning">
                        {{ __('Your email address is unverified.') }}
                        <a href="#" wire:click.prevent="resendVerificationNotification" class="text-warning">
                            {{ __('Click here to re-send the verification email.') }}
                        </a>
                    </div>
                @endif
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </form>

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
