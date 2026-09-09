<div>
    <flux:heading size="xl">{{ __('Create product manually') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('For a shop DipCatch cannot read automatically. Add the shops afterwards.') }}</flux:text>

    @if ($limitMessage)
        <flux:callout variant="warning" class="mt-4" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Plan limit reached') }}</flux:callout.heading>
            <flux:callout.text>{{ $limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="mt-6 space-y-4">
        <flux:input wire:model="title" :label="__('Title')" required />
        <flux:input wire:model="image_url" :label="__('Image URL')" type="url" />
        <flux:input wire:model="currency" :label="__('Currency')" maxlength="3" required />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="drop_threshold_pct" :label="__('Alert below (%)')" type="number" step="0.01" />
            <flux:input wire:model="drop_threshold_abs" :label="__('Alert below (amount)')" type="number" step="0.01" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('Create product') }}</flux:button>
    </form>
</div>
