{{--
    A Pro hint: one sentence on what Pro adds here, and a way to get it.
    Brand blue on the warm page, so the eye finds it without an alarm colour.
    `upsell` hints exist only to sell, so they hide when nothing can be bought;
    a limit hint keeps its explanation and drops the button.
--}}
@props([
    'upsell' => false,
    'user' => auth()->user(),
])

@php($pitch = \App\Billing\ProPitch::for($user))

@if ($pitch !== null && ($pitch->canBuy || ! $upsell))
    <div {{ $attributes->merge(['data-test' => 'pro-hint'])->class('flex flex-col gap-3 rounded-xl bg-brand/5 p-3 ring-1 ring-brand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-4 dark:bg-brand/15 dark:ring-brand/30') }}>
        <p class="flex items-start gap-2.5 text-base text-pretty text-ink sm:text-sm dark:text-zinc-100">
            <span class="mt-0.5 shrink-0 rounded-md bg-brand px-1.5 py-0.5 text-xs font-semibold text-white">{{ __('Pro') }}</span>
            <span>{{ $slot }}</span>
        </p>
        @if ($pitch->canBuy)
            <a href="{{ route('app.pro') }}" class="inline-flex shrink-0 items-center self-start rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-brand ring-1 ring-brand/25 hover:bg-brand/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:self-auto dark:bg-zinc-900 dark:text-white dark:ring-brand/50 dark:hover:bg-zinc-800">
                {{ $pitch->buttonLabel() }}
            </a>
        @endif
    </div>
@endif
