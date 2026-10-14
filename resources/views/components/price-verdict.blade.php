@props(['verdict' => null, 'range' => true])

{{-- Nothing without a verdict: a new shop must not look broken. The product
     card passes :range="false" because the whole card is already a tooltip
     trigger. --}}
@if ($verdict)
    @php($usualRange = $range ? $verdict->usualRange() : null)
    <div {{ $attributes->class('flex flex-wrap items-center gap-1.5') }} data-test="price-verdict">
        @if ($usualRange !== null)
            <flux:tooltip :content="$usualRange">
                <span tabindex="0" class="inline-flex cursor-help rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-test="price-verdict-range">
                    <flux:badge size="sm" :color="$verdict->verdict->color()" :icon="$verdict->verdict->icon()">{{ $verdict->verdict->label() }}</flux:badge>
                </span>
            </flux:tooltip>
        @else
            <flux:badge size="sm" :color="$verdict->verdict->color()" :icon="$verdict->verdict->icon()">{{ $verdict->verdict->label() }}</flux:badge>
        @endif
        @if ($verdict->lowestIn90Days)
            <flux:badge size="sm" color="blue" icon="trophy" data-test="lowest-in-90-days">{{ __('Lowest in 90 days') }}</flux:badge>
        @endif
    </div>
@endif
