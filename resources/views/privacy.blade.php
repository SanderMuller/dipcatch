@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, and `?lang=en` a duplicate that points back at the bare URL.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('privacy', ['lang' => 'nl']) : route('privacy');
    $contactEmail = config('site.contact_email');
    $updated = '2026-09-02';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', ['title' => __('Privacy')])
        <meta name="description" content="{{ __('What DipCatch stores about you, why, and how to get rid of it.') }}">
        <link rel="canonical" href="{{ $canonical }}">
        <link rel="alternate" hreflang="en" href="{{ route('privacy') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('privacy', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('privacy') }}">
        <meta property="og:locale" content="{{ $locale === 'nl' ? 'nl_NL' : 'en_US' }}">
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <header class="mx-auto flex w-full max-w-3xl items-center justify-between px-6 py-6 lg:px-8">
                <a href="{{ route('home', $langQuery) }}" aria-label="{{ __('Homepage') }}" class="flex items-center gap-2 font-semibold">
                    <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                        <x-app-logo-icon class="size-7" />
                    </span>
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>
                <nav class="flex items-center gap-2 sm:gap-3">
                    <div class="flex items-center rounded-full bg-white/80 p-0.5 text-[0.6875rem] font-semibold ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/80 dark:ring-zinc-800" role="group" aria-label="{{ __('Language') }}">
                        <a href="{{ route('privacy', ['lang' => 'nl']) }}" hreflang="nl" lang="nl" aria-label="Nederlands" @if ($locale === 'nl') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'nl', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'nl'])>nl</a>
                        <a href="{{ route('privacy', ['lang' => 'en']) }}" hreflang="en" lang="en" aria-label="English" @if ($locale === 'en') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'en', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'en'])>en</a>
                    </div>
                    <x-appearance-toggle />
                </nav>
            </header>

            <main class="mx-auto w-full max-w-3xl flex-1 px-6 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ __('Privacy') }}</h1>
                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Last updated :date', ['date' => $updated]) }}</p>

                <div class="mt-8 space-y-8 text-base text-zinc-700 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-zinc-900 [&_li]:mt-1 [&_ul]:list-disc [&_ul]:pl-5 dark:text-zinc-300 dark:[&_h2]:text-zinc-50">
                    <section>
                        <p>{{ __('DipCatch is a small price-tracking service run from the Netherlands. This page says what we store about you, why, and how to get rid of it. We keep it short on purpose; if something is unclear, ask.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('What we store') }}</h2>
                        <ul class="mt-2">
                            <li><strong>{{ __('Account') }}</strong> — {{ __('your name, email address, a hashed password, your timezone and default currency, and your notification preferences. Optional: two-factor secrets and browser-push subscriptions you enable yourself.') }}</li>
                            <li><strong>{{ __('Tracked products') }}</strong> — {{ __('the product links you paste, the titles, images and prices we read from those pages, the price history, and any private notes you add.') }}</li>
                            <li><strong>{{ __('Alerts') }}</strong> — {{ __('which price drops we told you about, and when.') }}</li>
                            <li><strong>{{ __('Technical') }}</strong> — {{ __('a session cookie to keep you signed in, and short-lived rate-limit counters keyed on your IP address to protect the shops we read prices from and this service.') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('What we do with it') }}</h2>
                        <p class="mt-2">{{ __('Only run the service: check the prices of the products you track, work out the cheapest shop, and send you the alerts you asked for. We do not sell data, we do not build advertising profiles, and we do not use tracking cookies or analytics scripts on this site.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Who else sees it') }}</h2>
                        <ul class="mt-2">
                            <li><strong>Laravel Cloud</strong> — {{ __('hosts the application and database.') }}</li>
                            <li><strong>Resend</strong> — {{ __('delivers our email (verification, password reset, the daily digest).') }}</li>
                            <li><strong>{{ __('Your browser’s push service') }}</strong> — {{ __('only if you turn on browser push; it receives the alert payloads.') }}</li>
                            <li><strong>Google</strong> — {{ __('shop logos on this site are loaded from Google’s favicon service by your browser, which sees the shop domain and your IP address. No account data is sent.') }}</li>
                            <li><strong>{{ __('The shops') }}</strong> — {{ __('we fetch product pages from the shops you track. Those requests come from our servers, not from you, and carry nothing about you.') }}</li>
                        </ul>
                        <p class="mt-2">{{ __('If you share a product page, anyone with that link can see the product, its prices and the shops — nothing about your account.') }}</p>
                        <p class="mt-2">{{ __('Product images on a shared page are loaded straight from the shop’s own servers by the viewer’s browser, so that shop sees the viewer’s IP address. We do not copy or store the images.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('How long') }}</h2>
                        <p class="mt-2">{{ __('As long as you have an account. Price-check history older than a year is pruned, keeping the most recent points so your charts keep working. Delete a product and its history goes with it. Delete your account (Settings → Profile) and everything goes.') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('Your rights') }}</h2>
                        <p class="mt-2">{{ __('Under the GDPR you can ask for a copy of your data, have it corrected, or have it deleted. You can already change or delete most of it yourself in the app.') }}
                            @if (filled($contactEmail))
                                {{ __('For anything else, email') }} <a href="mailto:{{ $contactEmail }}" class="font-medium text-zinc-900 underline underline-offset-4 dark:text-zinc-100">{{ $contactEmail }}</a>.
                            @endif
                        </p>
                    </section>
                </div>
            </main>

            <footer class="mx-auto w-full max-w-3xl px-6 pb-10 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-3 border-t border-zinc-200 pt-6 text-sm text-zinc-500 sm:flex-row dark:border-zinc-800 dark:text-zinc-400">
                    <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
                    <a href="{{ route('home', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Back to the homepage') }}</a>
                </div>
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
