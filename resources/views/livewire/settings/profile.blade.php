<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input id="name" wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input id="email" wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Send the email again.') }}
                            </flux:link>
                        </flux:text>

                    </div>
                @endif
            </div>

            {{-- No `required`: the browser refuses to submit a hidden required field. The server asks for it. --}}
            <div
                x-data="{ savedEmail: @js(Str::lower(auth()->user()->email)) }"
                x-show="$wire.email.toLowerCase() !== savedEmail"
                x-cloak
            >
                <flux:input
                    id="current_password"
                    wire:model="current_password"
                    :label="__('Current password')"
                    :description="__('Needed to change your email address.')"
                    type="password"
                    autocomplete="current-password"
                    viewable
                />
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>

        <livewire:settings.regional-preferences />

        <livewire:settings.usage-tracking />

        @if ($this->showDeleteUser)
            <livewire:settings.delete-user-form />
        @endif
    </x-settings.layout>
</section>
