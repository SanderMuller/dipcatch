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
@endphp

<span {{ $attributes->class('inline-flex flex-wrap items-baseline gap-x-2 tabular-nums') }}>
    <span>
        {{ \App\Support\MoneyFormatter::format($currentAmount === null ? null : (string) $currentAmount, $displayCurrency) }}{{ $currentAmount === null ? '' : $suffix }}
    </span>
    @if ($regularAmount !== null)
        <del title="{{ __('Regular price') }}" class="font-normal text-zinc-400 decoration-1 dark:text-zinc-500">
            {{ \App\Support\MoneyFormatter::format((string) $regularAmount, $displayCurrency) }}{{ $suffix }}
        </del>
    @endif
</span>
