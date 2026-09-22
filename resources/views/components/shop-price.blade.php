@props([
    'shop' => null,
    'fallback' => null,
    'currency' => null,
    'unit' => false,
])

@php
    $currentAmount = $unit ? $shop?->unitPrice() : ($shop?->current_price ?? $fallback);
    $regularAmount = null;

    if ($shop?->liveBundleOffer() !== null) {
        $singleItemPrice = $shop->singleItemPrice();
        $regularAmount = $unit ? $shop->unitPriceFor($singleItemPrice) : $singleItemPrice;
    }

    $suffix = $unit ? ' ' . $shop?->packUnitLabel() : '';
    $displayCurrency = $shop?->currency ?? $currency ?? 'EUR';
    // A rate needs more decimals than money does once it drops below one —
    // see MoneyFormatter::unitPrice().
    $money = $unit
        ? fn (?string $amount): string => \App\Support\MoneyFormatter::unitPrice($amount, $displayCurrency)
        : fn (?string $amount): string => \App\Support\MoneyFormatter::format($amount, $displayCurrency);
@endphp

<span {{ $attributes->class('inline-flex flex-wrap items-baseline gap-x-2 tabular-nums') }}>
    <span>
        {{ $money($currentAmount === null ? null : (string) $currentAmount) }}{{ $currentAmount === null ? '' : $suffix }}
    </span>
    @if ($regularAmount !== null)
        <del title="{{ __('Regular price') }}" class="font-normal text-zinc-400 decoration-1 dark:text-zinc-500">
            {{ $money((string) $regularAmount) }}{{ $suffix }}
        </del>
    @endif
</span>
