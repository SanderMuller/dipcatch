@props([
    'shop',
    'packs' => null,
])

{{-- What this shop costs per kilo, litre or piece *for this product* — or the
     one reason it takes no part in that comparison. A shop is never silently
     absent: it keeps its price and its place in the lowest-price answer, and
     says which fact is missing. --}}
@php
    $pack = $packs?->for($shop);
    $unitPrice = $packs?->unitPriceOf($shop);
@endphp

@php
    $notAConsumerPrice = $shop->notAConsumerPriceReason();
@endphp

@if ($shop->isReference())
    {{-- Ahead of everything: this shop holds no price at all, so every
         reason below it is about a comparison it was never in. --}}
    <flux:text size="sm" class="text-zinc-500">{{ $shop->kind->note() }}</flux:text>
@elseif ($notAConsumerPrice !== null)
    {{-- Ahead of every pack reason: this shop is out of both answers, not
         only out of the per-unit one. --}}
    <flux:text size="sm" class="text-amber-600 dark:text-amber-500">{{ $notAConsumerPrice }}</flux:text>
@elseif ($packs === null || ! $packs->hasComparisonUnit())
    <x-shop-price :shop="$shop" unit />
@elseif ($pack !== null && $pack->isExcluded())
    <flux:text size="sm" class="text-zinc-500">{{ $pack->reason() }}</flux:text>
@elseif ($unitPrice !== null)
    <span class="inline-flex flex-wrap items-baseline gap-x-2 tabular-nums">
        <span>{{ \App\Support\MoneyFormatter::unitPrice($unitPrice, $shop->currency) }} {{ $pack?->size?->label() }}</span>
        @if ($pack?->provenance === \App\Enums\PackProvenance::Inferred)
            <flux:tooltip content="{{ __('No pack size on this page. Taken from the other shops on this product, which all agree.') }}">
                <flux:badge size="sm" color="zinc">{{ __('estimated') }}</flux:badge>
            </flux:tooltip>
        @endif
    </span>
@endif
