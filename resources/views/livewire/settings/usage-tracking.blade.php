<section class="mt-10 space-y-6">
    <div>
        <flux:heading>{{ __('Privacy') }}</flux:heading>
        <flux:subheading>{{ __('Which pages you open in DipCatch, so we can see which features people use and where they get stuck') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="w-full space-y-6">
        <flux:switch
            wire:model="usage_tracking"
            :label="__('Use my visits to improve DipCatch')"
            :description="__('We keep the pages you open for 90 days, linked to your account, and use them only to improve DipCatch. Switch this off and we unlink the visits we have and stop linking new ones.')"
            data-test="usage-tracking"
        />

        <flux:text>
            <flux:link :href="route('privacy')">{{ __('Read how we count visits') }}</flux:link>
        </flux:text>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
