@props(['product'])

{{-- The live distance below the alert's reference, while the product is still down there. --}}
@php($drop = $product->activeDrop())

@if ($drop !== null)
    <flux:tooltip :content="$drop->wasLabel()">
        <flux:badge size="sm" color="emerald" icon="arrow-trending-down" {{ $attributes->merge(['class' => 'tabular-nums', 'data-test' => 'drop-badge']) }}>
            −{{ $product->activeDropPercent() }}%
        </flux:badge>
    </flux:tooltip>
@endif
