{{--
    A Pro hint: one sentence on what Pro adds here, and a way to get it.
    A frosted card with the brand gradient on the mark and the button, so the
    eye finds it on the warm page without an alarm colour. It stacks in a
    narrow column and lays out in a row where there is room.
    `upsell` hints exist only to sell, so they hide when nothing can be bought;
    a limit hint keeps its explanation and drops the button.
--}}
@props([
    'upsell' => false,
    'user' => auth()->user(),
])

@php($pitch = \App\Billing\ProPitch::for($user))

@if ($pitch !== null && ($pitch->canBuy || ! $upsell))
    <div {{ $attributes->merge(['data-test' => 'pro-hint'])->class('@container rounded-2xl bg-linear-to-r from-brand/5 via-white/40 to-violet-500/5 p-4 shadow-sm ring-1 ring-brand/15 backdrop-blur-md dark:from-brand/10 dark:via-zinc-900/60 dark:to-violet-500/10 dark:shadow-none dark:ring-white/10') }}>
        <div class="flex flex-col gap-3 @xl:flex-row @xl:items-center @xl:justify-between @xl:gap-6">
            <div class="flex min-w-0 flex-col gap-2 @md:flex-row @md:items-start @md:gap-3">
                <x-pro-badge class="self-start @md:mt-px" />
                <p class="text-base text-pretty text-ink sm:text-sm dark:text-zinc-100">{{ $slot }}</p>
            </div>
            @if ($pitch->canBuy)
                <a href="{{ route('app.pro') }}" wire:navigate class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full bg-linear-to-r from-pro to-pro-end py-2 pr-3.5 pl-3 text-sm font-medium text-white shadow-sm hover:brightness-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand @xl:self-auto dark:shadow-none">
                    <flux:icon.sparkles variant="micro" class="shrink-0" />
                    {{ $pitch->buttonLabel() }}
                </a>
            @endif
        </div>
    </div>
@endif
