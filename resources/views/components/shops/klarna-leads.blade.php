{{-- The shops a pasted Klarna page lists. No links: Klarna's offer links are
     paid click redirects DipCatch never follows. --}}
@props(['leads', 'lookingUp' => false])

<div {{ $attributes->class('mt-2') }} data-test="klarna-leads">
    <p>{{ __('Klarna lists these shops:') }}</p>
    <ul role="list" class="mt-1 space-y-0.5">
        @foreach ($leads as $lead)
            <li class="flex min-w-0 gap-2 tabular-nums" wire:key="klarna-lead-{{ $loop->index }}">
                <span class="shrink-0 font-medium">{{ $lead['shop'] }}</span>
                <span class="shrink-0">{{ \App\Support\MoneyFormatter::format($lead['price'], $lead['currency']) }}</span>
                <span class="min-w-0 truncate opacity-80" title="{{ $lead['title'] }}">{{ $lead['title'] }}</span>
            </li>
        @endforeach
    </ul>
    @if ($lookingUp)
        <p class="mt-2" data-test="klarna-leads-looking-up">{{ __('DipCatch is looking these shops up. Matches show under shop suggestions.') }}</p>
    @endif
</div>
