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
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', ['title' => __('Pricing')])
        <meta name="description" content="{{ __('DipCatch is free for 20 products. Pro removes the limits and checks prices more often.') }}">
        <link rel="canonical" href="{{ $canonical }}">
        <link rel="alternate" hreflang="en" href="{{ route('pricing') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('pricing', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('pricing') }}">
        <meta property="og:locale" content="{{ $locale === 'nl' ? 'nl_NL' : 'en_US' }}">
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <header class="mx-auto flex w-full max-w-4xl items-center justify-between px-6 py-6 lg:px-8">
                <a href="{{ route('home', $langQuery) }}" aria-label="{{ __('Homepage') }}" class="flex items-center gap-2 font-semibold">
                    <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                        <x-app-logo-icon class="size-7" />
                    </span>
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>
                <nav class="flex items-center gap-2 sm:gap-3">
                    <div class="flex items-center rounded-full bg-white/80 p-0.5 text-[0.6875rem] font-semibold ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/80 dark:ring-zinc-800" role="group" aria-label="{{ __('Language') }}">
                        <a href="{{ route('pricing', ['lang' => 'nl']) }}" hreflang="nl" lang="nl" aria-label="Nederlands" @if ($locale === 'nl') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'nl', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'nl'])>nl</a>
                        <a href="{{ route('pricing', ['lang' => 'en']) }}" hreflang="en" lang="en" aria-label="English" @if ($locale === 'en') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'en', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'en'])>en</a>
                    </div>
                    <x-appearance-toggle />
                </nav>
            </header>

            <main class="mx-auto w-full max-w-4xl flex-1 px-6 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Pricing') }}</h1>
                <p class="mt-2 max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                    {{ __('Track your first :count products for nothing. Pro removes the limits and looks more often.', ['count' => $free->maxProducts()]) }}
                </p>

                <div class="mt-10 grid gap-6 sm:grid-cols-2">
                    <section class="rounded-2xl bg-white/70 p-6 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/70 dark:ring-zinc-800">
                        <h2 class="text-lg font-semibold">{{ __('Free') }}</h2>
                        <p class="mt-1 text-3xl font-semibold tracking-tight">{{ \App\Support\MoneyFormatter::symbol(\App\Billing\ProPrice::currency()) }}0</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No card, no expiry.') }}</p>

                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li>{{ __(':count products', ['count' => $free->maxProducts()]) }}</li>
                            <li>{{ __(':count shops per product', ['count' => $free->maxShopsPerProduct()]) }}</li>
                            <li>{{ __('Prices checked every :hours hours', ['hours' => $free->recheckIntervalHours()]) }}</li>
                            <li>{{ __('Price drop alerts and the daily digest') }}</li>
                        </ul>

                        <a href="{{ $ctaHref }}" class="mt-8 inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            {{ $authed ? __('Your plan') : __('Create a free account') }}
                        </a>
                    </section>

                    <section class="rounded-2xl bg-white p-6 ring-2 ring-zinc-900 dark:bg-zinc-900 dark:ring-white">
                        <h2 class="text-lg font-semibold">{{ __('Pro') }}</h2>
                        <p class="mt-1 text-3xl font-semibold tracking-tight">{{ $price }}<span class="text-base font-normal text-zinc-500 dark:text-zinc-400"> / {{ __('month') }}</span></p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $trialDays > 0 ? __(':days days free, cancel any time.', ['days' => $trialDays]) : __('Cancel any time.') }}
                        </p>

                        <ul class="mt-6 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                            <li>{{ __('Unlimited products') }}</li>
                            <li>{{ __('Unlimited shops per product') }}</li>
                            <li>{{ __('Prices checked every :hours hours', ['hours' => $pro->recheckIntervalHours()]) }}</li>
                            <li>{{ __('Unit price alerts — your target per kilo, litre or piece') }}</li>
                            <li>{{ __('A higher alert ceiling') }}</li>
                        </ul>

                        <a href="{{ $ctaHref }}" class="mt-8 inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            {{ $authed ? __('Upgrade to Pro') : __('Start with Pro') }}
                        </a>
                    </section>
                </div>

                <p class="mt-8 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('If you drop back to Free, nothing you track is deleted or paused. You keep everything you already added; you just cannot add more until you are back under the free limit.') }}
                </p>
            </main>

            <footer class="mx-auto w-full max-w-4xl px-6 pb-10 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-3 border-t border-zinc-200 pt-6 text-sm text-zinc-500 sm:flex-row dark:border-zinc-800 dark:text-zinc-400">
                    <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
                    <a href="{{ route('home', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Back to the homepage') }}</a>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
