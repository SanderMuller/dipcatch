@php
    $free = \App\Billing\Entitlements::of(\App\Billing\Plan::Free);
    $pro = \App\Billing\Entitlements::of(\App\Billing\Plan::Pro);
    $benefits = [
        ['building-storefront', __('Every shop you like'), __('Compare a product at as many shops as you want. Free stops at :count.', ['count' => $free->maxShopsPerProduct()])],
        ['clock', __('Checked every :hours hours', ['hours' => $pro->recheckIntervalHours()]), __('A short deal reaches you the same day, not the day after.')],
        ['chart-bar', __('Your full price history'), __('A year of prices, so you can tell a real low from the usual offer.')],
        ['shield-check', __('Every price change explained'), __('Each move of the lowest price, with its price alerts and the wrong prices DipCatch caught.')],
        ['share', __('Public links'), __('Share a product page with its prices and shops, for a friend or a group chat.')],
        ['magnifying-glass', __('More shops, found for you'), __('DipCatch searches the web for other shops that sell what you follow, by name and by barcode.')],
    ];
    // Everything here waits for the account's own switch; none of it runs by itself.
    $aiHelp = [
        [__('Finds more shops'), __('Searches the web, comparison sites included, and reads each page before it suggests a shop.')],
        [__('Catches the wrong product'), __('Warns you when a new shop sells another flavour or pack size.')],
        [__('Keeps pack sizes right'), __('Checks a page that hides its pack size, so the price per kilo and your alerts stay correct.')],
        [__('Suggests a smarter alert price'), __('Looks at how deep this product usually goes on sale, and sets the suggestion from that.')],
        [__('Sorts your products'), __('Files every product into a category, the ones you already follow too.')],
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

    <section class="mx-auto mt-20 grid max-w-5xl gap-x-12 gap-y-8 rounded-2xl bg-paper p-6 ring-1 ring-line sm:p-10 lg:grid-cols-[2fr_3fr] dark:bg-zinc-900 dark:ring-white/10" aria-labelledby="pro-ai">
        <div>
            <p class="flex items-center gap-2 text-base font-medium text-brand sm:text-sm">
                <flux:icon.sparkles variant="micro" />
                {{ __('AI help') }}
            </p>
            <h2 id="pro-ai" class="mt-3 max-w-[20ch] text-2xl font-semibold tracking-tight text-balance text-ink dark:text-white">{{ __('An assistant that checks every shop for you') }}</h2>
            <p class="mt-3 max-w-[44ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __('Each check stays off until you switch it on in your settings. Once it is on, it runs by itself.') }}</p>
        </div>
        <dl class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            @foreach ($aiHelp as [$title, $text])
                <div>
                    <dt class="flex items-start gap-2 text-base font-semibold text-ink dark:text-white">
                        <svg viewBox="0 0 16 16" class="mt-1 size-4 shrink-0 fill-savings-strong dark:fill-savings" aria-hidden="true"><path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" /></svg>
                        {{ $title }}
                    </dt>
                    <dd class="mt-1 pl-6 text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-400">{{ $text }}</dd>
                </div>
            @endforeach
        </dl>
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
