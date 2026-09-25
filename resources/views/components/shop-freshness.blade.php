@props(['shop'])

{{-- How old the price beside this is, and whether it is still being read.

     `last_checked_at` is stamped by every attempt, a failed one included, so a
     shop failing for a week showed "2 hours ago" next to a week-old price and
     nothing said otherwise. The price simply stopped moving and looked current
     while it did. --}}
@php
    $readAt = $shop->priceReadAt();
    $failing = $shop->readsAreFailing();
@endphp

@if ($readAt === null)
    <span {{ $attributes->merge(['class' => 'text-zinc-500']) }}>{{ __('never read') }}</span>
@elseif ($failing)
    <span {{ $attributes->merge(['class' => 'text-amber-700 dark:text-amber-500']) }}>
        <flux:tooltip :content="__('DipCatch has not been able to read this shop since then, so the price is as old as that.')">
            <span>{{ __('read :ago, failing since', ['ago' => $readAt->diffForHumans()]) }}</span>
        </flux:tooltip>
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-zinc-500']) }}>
        <flux:tooltip :content="__('Price last read :date.', ['date' => $readAt->setTimezone(auth()->user()?->timezone ?? config('app.timezone'))->format('j M Y, H:i')])">
            <span tabindex="0" class="cursor-help rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-test="freshness-hint">{{ $readAt->diffForHumans() }}</span>
        </flux:tooltip>
    </span>
@endif
