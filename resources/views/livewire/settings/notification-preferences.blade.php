<div>
    <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('How and when DipCatch tells you a price dropped.') }}</flux:text>

    <form wire:submit="save" class="mt-6 space-y-6">
        <flux:card>
            <flux:heading size="lg">{{ __('Channels') }}</flux:heading>

            <div class="mt-4 space-y-4">
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
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">{{ __('Regional') }}</flux:heading>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="timezone" variant="listbox" searchable :label="__('Timezone')" :placeholder="__('Search time zones…')">
                    @foreach ($timezones as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="default_currency" :label="__('Default currency')" maxlength="3" />
            </div>
        </flux:card>

        @if ($autoCategoriesAvailable)
            <flux:card>
                <flux:heading size="lg">{{ __('Products') }}</flux:heading>

                <div class="mt-4 space-y-4">
                    {{-- Composed by hand: `flux:switch` takes its label as a
                         string, so it has no room for the badge. The badge
                         shows on Pro too, so a subscriber knows what the plan
                         pays for. --}}
                    <flux:field variant="inline">
                        <flux:label>
                            {{ __('Sort new products into a category automatically') }}
                            <flux:badge size="sm" color="zinc" class="ms-2" data-test="auto-categories-pro">{{ __('Pro') }}</flux:badge>
                        </flux:label>
                        <flux:description>
                            {{ $allowsAutoCategories
                                ? __('Products you add from now on. Products you already track keep their category.')
                                : __('Pro sorts products for you. Your choice is kept, and it starts working when you upgrade.') }}
                        </flux:description>
                        <flux:switch
                            wire:model="auto_categories"
                            :disabled="! $allowsAutoCategories"
                            data-test="auto-categories"
                        />
                        <flux:error name="auto_categories" />
                    </flux:field>
                </div>
            </flux:card>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <flux:button type="submit" variant="primary">{{ __('Save settings') }}</flux:button>
            <flux:button type="button" variant="ghost" wire:click="sendTest">{{ __('Send a test notification') }}</flux:button>
        </div>
    </form>

    @include('partials.push-subscription')
</div>
