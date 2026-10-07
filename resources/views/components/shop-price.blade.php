@props([
    'shop' => null,
    'fallback' => null,
    'currency' => null,
])

@php
    // Pack money only. A price per unit comes from the product's resolver,
    // which knows which of a shop's sizes it is compared by: x-shop-unit-price.
    $currentAmount = $shop?->current_price ?? $fallback;
    $regularAmount = null;

    if ($shop?->liveBundleOffer() !== null) {
        $regularAmount = $shop->singleItemPrice();
    }

    $displayCurrency = $shop?->currency ?? $currency ?? 'EUR';
    $money = fn (?string $amount): string => \App\Support\MoneyFormatter::format($amount, $displayCurrency);
@endphp

@if ($shop?->isReference())
    {{-- No price, and no dash pretending one is missing: nothing was ever
         read here, which is what the row is for. --}}
    <flux:badge size="sm" color="zinc">{{ __('Link only') }}</flux:badge>
@else
<span {{ $attributes->class('inline-flex flex-wrap items-baseline gap-x-2 tabular-nums') }}>
    <span>
        {{ $money($currentAmount === null ? null : (string) $currentAmount) }}
    </span>
    @if ($regularAmount !== null)
        <del title="{{ __('Regular price') }}" class="font-normal text-zinc-400 decoration-1 dark:text-zinc-500">
            {{ $money((string) $regularAmount) }}
        </del>
    @endif
</span>
@endif
