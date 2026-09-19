<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('app.products.create')" wire:navigate>{{ __('Track a product') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('By hand') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1">{{ __('Fill in a product yourself') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('Use this when we could not read the shop page. Add the shop links afterwards, on the product page.') }}</flux:text>

    @if ($limitMessage)
        <flux:callout variant="warning" class="mt-4" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('You have used all your free products') }}</flux:callout.heading>
            <flux:callout.text>{{ $limitMessage }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="mt-6 space-y-4">
        <flux:input wire:model="title" :label="__('Title')" required />
        <flux:input wire:model="image_url" :label="__('Image URL')" type="url" />
        <flux:input wire:model="currency" :label="__('Currency')" maxlength="3" required />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="drop_threshold_pct" :label="__('Alert me when it drops by (%)')" type="number" step="0.01" />
            <flux:input wire:model="drop_threshold_abs" :label="__('Alert me when it drops by (amount)')" type="number" step="0.01" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save product') }}</flux:button>
    </form>
</div>
