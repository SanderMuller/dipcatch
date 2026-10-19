@props([
    'shop',
    'packs' => null,
    // A tooltip on the price alone: wrapped around the whole figure it also
    // fired over the badge beside it, whose own tooltip it then covered.
    'hint' => null,
])

{{-- What this shop costs per kilo, litre or piece *for this product* — or the
     one reason it takes no part in that comparison. A shop is never silently
     absent: it keeps its price and its place in the lowest-price answer, and
     says which fact is missing. --}}
@php
    $pack = $packs?->for($shop);
    $unitPrice = $packs?->unitPriceOf($shop);
    // Where the price per item leads, it is the figure this row shows.
    $itemPrice = $packs?->leadsWithItems() === true ? $packs->items()?->priceFor($shop) : null;
    $figure = $itemPrice !== null
        ? __(':price each', ['price' => \App\Support\MoneyFormatter::format($itemPrice, $shop->currency)])
        : ($unitPrice === null ? null : \App\Support\MoneyFormatter::unitPrice($unitPrice, $shop->currency) . ' ' . $pack?->size?->label());
@endphp

@php
    $notAConsumerPrice = $shop->notAConsumerPriceReason();
@endphp

@if ($shop->isReference())
    {{-- Ahead of everything: this shop holds no price at all, so every
         reason below it is about a comparison it was never in. --}}
    {{-- A sentence: it wraps, where the table cell does not, so the table
         does not scroll sideways. --}}
    <flux:text size="sm" class="whitespace-normal text-zinc-500">{{ $shop->linkNote() }}</flux:text>
@elseif ($notAConsumerPrice !== null)
    {{-- Ahead of every pack reason: this shop is out of both answers, not
         only out of the per-unit one. --}}
    <flux:text size="sm" class="text-amber-600 dark:text-amber-500">{{ $notAConsumerPrice }}</flux:text>
@elseif ($packs === null || ! $packs->hasComparisonUnit())
    {{-- Nothing to compare in: no unit figure, from the shop's own size or otherwise. --}}
@elseif ($pack !== null && $pack->isExcluded())
    <flux:text size="sm" class="text-zinc-500">{{ $pack->reason() }}</flux:text>
@elseif ($unitPrice !== null)
    <span class="inline-flex flex-wrap items-baseline gap-x-2 tabular-nums">
        @if ($hint !== null)
            <flux:tooltip :content="$hint">
                <span tabindex="0" class="cursor-help rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-test="unit-price-hint">{{ $figure }}</span>
            </flux:tooltip>
        @else
            <span>{{ $figure }}</span>
        @endif
        @if ($pack?->provenance === \App\Enums\PackProvenance::Inferred)
            <flux:tooltip content="{{ __('No pack size on this page. Taken from the other shops on this product, which all agree.') }}">
                <flux:badge size="sm" color="zinc">{{ __('estimated') }}</flux:badge>
            </flux:tooltip>
        @elseif ($pack?->provenance === \App\Enums\PackProvenance::Converted)
            <flux:tooltip content="{{ __('This page gives the pack as :size. Estimated from what one item weighs at the other shops, which all agree.', ['size' => $shop->packSize() === null ? '' : \App\Support\UnitWord::pack($shop->packSize())]) }}">
                <flux:badge size="sm" color="zinc" data-test="pack-converted">{{ __('estimated') }}</flux:badge>
            </flux:tooltip>
        @elseif ($pack?->provenance === \App\Enums\PackProvenance::Confirmed)
            <flux:tooltip content="{{ __('No pack size on this page. The AI check read the page as the size the other shops state.') }}">
                <flux:badge size="sm" color="zinc" data-test="pack-checked-by-ai">{{ __('size checked by AI') }}</flux:badge>
            </flux:tooltip>
        @endif
    </span>
@endif
