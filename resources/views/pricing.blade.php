@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('pricing', ['lang' => 'nl']) : route('pricing');
    $free = \App\Billing\Entitlements::of(\App\Billing\Plan::Free);
    $pro = \App\Billing\Entitlements::of(\App\Billing\Plan::Pro);
    $price = \App\Billing\ProPrice::label();
    $trialDays = \App\Billing\ProPrice::trialDays();
    $authed = auth()->check();
    $ctaHref = $authed ? url('/app/billing') : route('register');
    // Pro has its own destination: `upgrade` decides between registration,
    // checkout and the billing page, so the card links to one URL whoever
    // is reading it.
    $isPro = $authed && auth()->user()?->isPro() === true;
    $proCtaHref = $isPro ? url('/app/billing') : route('upgrade');
    $proCtaLabel = match (true) {
        $isPro => __('Your plan'),
        $authed => __('Upgrade to Pro'),
        default => __('Start with Pro'),
    };
    $onSale = \App\Billing\BillingGate::isOpen();
    // maxProducts() is nullable, where null means unlimited, so it cannot be
    // interpolated without a branch.
    $freeProducts = $free->maxProducts();
    $description = $freeProducts === null
        ? __('DipCatch is free, with no limit on how much you track. Pro checks prices more often.')
        : __('DipCatch is free for :count products. Pro removes the limits and checks prices more often.', ['count' => $freeProducts]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Pricing'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('pricing') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('pricing', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('pricing') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::pricing($description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />


            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Pricing') }}</h1>
                <p class="mt-2 max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                    {{ __('Track your first :count products for nothing. Pro removes the limits and looks more often.', ['count' => $free->maxProducts()]) }}
                </p>

                <div class="mt-10 grid max-w-4xl gap-6 sm:grid-cols-2">
                    <section class="rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm">
                        <h2 class="text-lg font-semibold">{{ __('Free') }}</h2>
                        <p class="mt-1 text-3xl font-semibold tracking-tight">{{ \App\Support\MoneyFormatter::symbol(\App\Billing\ProPrice::currency()) }}0</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No card, no expiry.') }}</p>

                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li>{{ __(':count products', ['count' => $free->maxProducts()]) }}</li>
                            <li>{{ __(':count shops per product', ['count' => $free->maxShopsPerProduct()]) }}</li>
                            <li>{{ __('Prices checked every :hours hours', ['hours' => $free->recheckIntervalHours()]) }}</li>
                            <li>{{ __('Price drop alerts and one email a day') }}</li>
                            <li>{{ __(':days days of price history', ['days' => $free->historyDays()]) }}</li>
                        </ul>

                        <a href="{{ $ctaHref }}" class="mt-8 inline-flex items-center rounded-full bg-paper px-5 py-3 text-base font-medium text-ink ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm">
                            {{ $authed ? __('Your plan') : __('Create a free account') }}
                        </a>
                    </section>

                    <section class="rounded-2xl bg-paper p-6 shadow-xl shadow-ink/5 ring-2 ring-ink dark:shadow-none">
                        <h2 class="text-lg font-semibold">{{ __('Pro') }}</h2>
                        <p class="mt-1 text-3xl font-semibold tracking-tight text-brand tabular-nums">{{ $price }}<span class="text-base font-normal text-zinc-500 dark:text-zinc-400"> / {{ __('month') }}</span></p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('VAT included.') }}</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($onSale)
                                {{ $trialDays > 0 ? __(':days days free, cancel any time.', ['days' => $trialDays]) : __('Cancel any time.') }}
                            @else
                                {{ __('Not on sale yet.') }}
                            @endif
                        </p>

                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li>{{ __('Unlimited products') }}</li>
                            <li>{{ __('Unlimited shops per product') }}</li>
                            <li>{{ __('Prices checked every :hours hours', ['hours' => $pro->recheckIntervalHours()]) }}</li>
                            <li>{{ __('A target price per kilo, litre or piece') }}</li>
                            <li>{{ __('More alerts per hour') }}</li>
                            <li>{{ __('Full price history, kept for as long as you subscribe') }}</li>
                        </ul>

                        @if ($onSale)
                            <a href="{{ $proCtaHref }}" class="mt-8 inline-flex items-center rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">
                                {{ $proCtaLabel }}
                            </a>
                        @else
                            <p class="mt-8 inline-flex items-center rounded-full bg-ink/5 px-5 py-3 text-base font-medium text-zinc-500 ring-1 ring-line sm:text-sm dark:text-zinc-400">
                                {{ __('Coming soon') }}
                            </p>
                        @endif
                    </section>
                </div>

                <p class="mt-8 max-w-[64ch] text-sm text-pretty text-zinc-500 dark:text-zinc-400">
                    {{ __('If you go back to Free, nothing you follow is deleted or stopped. You keep everything you added. You just cannot add more until you are under the free limit again.') }}
                </p>
            </main>

            <footer class="mx-auto w-full max-w-app px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="config('site.contact_email')" :home="true" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
