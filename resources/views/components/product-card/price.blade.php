@props(['product', 'headline'])

{{--
    The old figure shows only in the basis of the headline: a struck pack price
    beside a pack headline, "Was €x/kg" beside a per-kilo headline. A drop
    measured in another basis shows no old figure, because "Was €12" beside
    "€0.0275 /piece" compares two different things. Its badge says which basis
    it was measured in.
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
            {{ $headline->text() }}
        </span>

        @if ($drop !== null)
            @if ($drop->comparison_unit === $headline->unit && $drop->comparison_unit === null && $drop->reference_price !== null)
                <del class="text-sm text-zinc-400 decoration-1 dark:text-zinc-400" title="{{ $drop->wasLabel() }}">
                    {{ \App\Support\MoneyFormatter::format((string) $drop->reference_price, $drop->currency) }}
                </del>
            @elseif ($drop->comparison_unit === $headline->unit && $drop->comparison_unit !== null && $drop->reference_unit_price !== null)
                <span class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('Was :price', ['price' => \App\Support\MoneyFormatter::unitPrice((string) $drop->reference_unit_price, $drop->currency) . ' ' . \App\Support\UnitWord::labelFor($drop->comparison_unit)]) }}
                </span>
            @elseif ($drop->comparison_unit === $headline->unit && $drop->comparison_unit === null)
                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $drop->wasLabel() }}</span>
            @endif

            <span @class([
                'rounded-md px-1.5 py-0.5 text-xs font-bold',
                'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300' => $product->active,
                'bg-zinc-200 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => ! $product->active,
            ]) data-test="drop-badge" title="{{ __('Measured :unit', ['unit' => \App\Support\UnitWord::forCode($drop->comparison_unit) ?? __('per pack')]) }}">
                −{{ $dropPercent }}%
            </span>
        @endif
    </div>
</div>
