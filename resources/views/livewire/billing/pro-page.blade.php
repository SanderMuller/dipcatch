@php
    $free = \App\Billing\Entitlements::of(\App\Billing\Plan::Free);
    $pro = \App\Billing\Entitlements::of(\App\Billing\Plan::Pro);
    $benefits = [
        ['building-storefront', __('Every shop you like'), __('Compare a product at as many shops as you want. Free stops at :count.', ['count' => $free->maxShopsPerProduct()])],
        ['clock', __('Checked every :hours hours', ['hours' => $pro->recheckIntervalHours()]), __('A short deal reaches you the same day, not the day after.')],
        ['chart-bar', __('Your full price history'), __('A year of prices, so you can tell a real low from the usual offer.')],
        ['shield-check', __('Every price change explained'), __('Each move of the lowest price, with its price alerts and the wrong prices DipCatch caught.')],
        ['share', __('Public links'), __('Share a product page with its prices and shops, for a friend or a group chat.')],
        ['sparkles', __('AI help, when you want it'), __('Products sorted into categories, and a check that a new shop sells the same pack.')],
    ];
    $cta = 'inline-flex items-center justify-center rounded-full bg-ink px-6 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200';
@endphp

<div>
    <section class="mx-auto max-w-3xl pt-4 text-center sm:pt-10">
        <p class="text-base font-medium text-brand sm:text-sm">{{ __('DipCatch Pro') }}</p>
        <h1 class="mx-auto mt-3 max-w-[22ch] text-3xl font-semibold tracking-tight text-balance text-ink sm:text-5xl dark:text-white">{{ __('Catch every good price, at every shop') }}</h1>
        <p class="mx-auto mt-5 max-w-[56ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">
            {{ __('Follow up to :count products at as many shops as you like. DipCatch checks them more often, keeps every price, and tells you what it did.', ['count' => $pro->maxProducts()]) }}
        </p>

        @if ($onSale)
            <div class="mt-8 flex flex-col items-center gap-3">
                <a href="{{ route('billing.checkout') }}" class="{{ $cta }}" data-test="pro-page-cta">{{ $ctaLabel }}</a>
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                    {{ $offersTrial ? __('No charge before the trial ends. Cancel any time.') : __('Cancel any time.') }}
                    @if ($yearlyLabel !== null)
                        <a href="{{ route('billing.checkout', ['interval' => 'yearly']) }}" class="font-medium text-ink underline decoration-line underline-offset-4 hover:text-brand dark:text-white">{{ $yearlyLabel }}</a>
                    @endif
                </p>
            </div>
        @else
            <p class="mt-8 text-base text-zinc-500 dark:text-zinc-400">{{ __('Pro is not on sale yet.') }}</p>
        @endif
    </section>

    <section class="mx-auto mt-16 max-w-5xl" aria-labelledby="pro-benefits">
        <h2 id="pro-benefits" class="sr-only">{{ __('What Pro adds') }}</h2>
        <ul role="list" class="grid gap-x-10 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($benefits as [$icon, $title, $text])
                <li class="flex gap-4">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand dark:bg-brand/20 dark:text-white">
                        <flux:icon :icon="$icon" variant="outline" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-ink dark:text-white">{{ $title }}</h3>
                        <p class="mt-1 text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-400">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="mt-20" aria-labelledby="pro-compare">
        <h2 id="pro-compare" class="text-center text-2xl font-semibold tracking-tight text-balance text-ink dark:text-white">{{ __('Free and Pro, side by side') }}</h2>
        <x-plans.comparison
            class="mt-8"
            sticky-top="top-[4.75rem]"
            :pro-cta="['href' => route('billing.checkout'), 'label' => $offersTrial ? __('Try Pro free') : __('Get Pro'), 'short' => $offersTrial ? __('Try free') : __('Get Pro')]"
            :yearly-cta="$yearlyLabel === null ? null : ['href' => route('billing.checkout', ['interval' => 'yearly']), 'label' => $yearlyLabel]"
        />
    </section>

    @if ($onSale)
        <section class="mx-auto mt-16 flex max-w-3xl flex-col items-center gap-3 rounded-2xl bg-soft-yellow/70 px-6 py-10 text-center ring-1 ring-line dark:bg-zinc-900 dark:ring-white/10">
            <h2 class="max-w-[30ch] text-2xl font-semibold tracking-tight text-balance text-ink dark:text-white">{{ __('Try it on the products you already follow') }}</h2>
            <p class="max-w-[52ch] text-base text-pretty text-zinc-600 dark:text-zinc-300">{{ __('If you go back to Free, nothing you follow is deleted or stopped.') }}</p>
            <a href="{{ route('billing.checkout') }}" class="{{ $cta }} mt-3">{{ $ctaLabel }}</a>
        </section>
    @endif
</div>
