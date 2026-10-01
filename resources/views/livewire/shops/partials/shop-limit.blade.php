{{--
    A product at its plan's shop limit, where the add-shop form would be.
    Needs `$shopLimit` and `$shopCount`.
--}}
<flux:callout icon="lock-closed" data-test="shop-limit">
    <flux:callout.heading>This product is at its shop limit</flux:callout.heading>
    <flux:callout.text>
        Your plan compares up to {{ $shopLimit }} {{ Str::plural('shop', $shopLimit ?? 0) }} per product, and this one has {{ $shopCount }}. All of them keep being checked. Only adding another one is blocked.
    </flux:callout.text>
    <flux:button class="mt-3" size="sm" :href="route('app.billing')" wire:navigate>
        Compare plans
    </flux:button>
</flux:callout>
