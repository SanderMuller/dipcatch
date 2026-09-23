@props(['product'])

{{--
    The old price is struck through only for a drop measured on the pack
    price. A drop measured per unit can reference another shop's pack, so
    beside this price it would state a drop the badge does not. It states the
    old unit price instead, the basis the badge uses.
--}}
{{-- A drop the price has climbed back out of reads as −0%: shown as none. --}}
@php($dropPercent = $product->activeDropPercent())
@php($drop = $dropPercent > 0 ? $product->activeDrop() : null)

<div {{ $attributes->class('tabular-nums') }}>
    @if (! $product->active)
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Last price read') }}</span>
    @endif
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span @class([
            'text-2xl font-bold tracking-tight',
            'text-orange-600 dark:text-orange-400' => $drop !== null && $product->active,
            'text-zinc-900 dark:text-white' => $drop === null || ! $product->active,
        ])>
            @php($currentPrice = $product->cheapestShop?->current_price ?? $product->cheapest_price)
            {{ \App\Support\MoneyFormatter::format($currentPrice === null ? null : (string) $currentPrice, $product->cheapestShop?->currency ?? $product->currency) }}
        </span>

        @if ($drop !== null)
            @if ($drop->comparison_unit === null && $drop->reference_price !== null)
                <del class="text-sm text-zinc-400 decoration-1 dark:text-zinc-400" title="{{ $drop->wasLabel() }}">
                    {{ \App\Support\MoneyFormatter::format((string) $drop->reference_price, $drop->currency) }}
                </del>
            @elseif ($drop->comparison_unit !== null && $drop->reference_unit_price !== null)
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Was :price', ['price' => \App\Support\MoneyFormatter::unitPrice((string) $drop->reference_unit_price, $drop->currency) . \App\Support\UnitWord::labelFor($drop->comparison_unit)]) }}
                </span>
            @else
                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $drop->wasLabel() }}</span>
            @endif

            <span @class([
                'rounded-md px-1.5 py-0.5 text-xs font-bold',
                'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300' => $product->active,
                'bg-zinc-200 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => ! $product->active,
            ]) data-test="drop-badge">
                −{{ $dropPercent }}%
            </span>
        @endif
    </div>
</div>
