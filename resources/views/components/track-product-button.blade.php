{{--
    The add action on the dashboard and the product list. At the plan limit
    the button stays in place, disabled, with the limit stated: the guard
    also refuses the write.
--}}
@props(['canAdd'])

@if ($canAdd)
    <flux:button class="rounded-full!" :href="route('app.products.create')" icon="plus" variant="primary" wire:navigate data-test="track-product">
        {{ __('Track a product') }}
    </flux:button>
@else
    <flux:tooltip content="{{ auth()->user()?->isPro() === true ? __('You are following as many products as Pro allows.') : __('You are following as many products as the free plan allows.') }}">
        <flux:button class="rounded-full!" icon="plus" variant="primary" disabled data-test="track-product">{{ __('Track a product') }}</flux:button>
    </flux:tooltip>
@endif
