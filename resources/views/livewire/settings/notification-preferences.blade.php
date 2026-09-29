<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Notification settings') }}</flux:heading>

    <x-settings.layout :heading="__('Notifications')" :subheading="__('How DipCatch tells you a price dropped')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:switch
                wire:model="notify_via_email"
                :label="__('One email a day')"
                :description="__('One email per day at 09:00 in your local timezone, grouped by product.')"
            />

            {{-- The column is named notify_via_filament for history; what the
                 user is choosing is in-app delivery. --}}
            <flux:switch
                wire:model="notify_via_filament"
                :label="__('In-app (bell) notifications')"
                :description="__('Sent right away, one entry per drop.')"
            />

            <flux:switch
                wire:model="notify_via_push"
                :label="__('Browser push notifications')"
                :description="__('Needs permission from this browser.')"
            />

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button type="button" variant="ghost" wire:click="sendTest">{{ __('Send a test notification') }}</flux:button>
            </div>
        </form>

        @include('partials.push-subscription')
    </x-settings.layout>
</section>
