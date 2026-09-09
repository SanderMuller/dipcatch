<div>
    <flux:heading size="xl">{{ __('Notifications') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('How and when DipCatch tells you a price dropped.') }}</flux:text>

    <form wire:submit="save" class="mt-6 space-y-6">
        <flux:card>
            <flux:heading size="lg">{{ __('Channels') }}</flux:heading>

            <div class="mt-4 space-y-4">
                <flux:switch
                    wire:model="notify_via_email"
                    :label="__('Daily email digest')"
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
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Regional') }}</flux:heading>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="timezone" :label="__('Timezone')">
                    @foreach ($timezones as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="default_currency" :label="__('Default currency')" maxlength="3" />
            </div>
        </flux:card>

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save preferences') }}</flux:button>
            <flux:button type="button" variant="ghost" wire:click="sendTest">{{ __('Send a test notification') }}</flux:button>

            <flux:text x-data="{ shown: false }" x-on:saved.window="shown = true; setTimeout(() => shown = false, 3000)" x-show="shown" x-cloak class="text-green-600">
                {{ __('Saved.') }}
            </flux:text>
            <flux:text x-data="{ shown: false }" x-on:test-sent.window="shown = true; setTimeout(() => shown = false, 3000)" x-show="shown" x-cloak class="text-green-600">
                {{ __('Test notification sent.') }}
            </flux:text>
        </div>
    </form>

    @include('partials.push-subscription')
</div>
