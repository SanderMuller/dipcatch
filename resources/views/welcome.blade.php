@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, and `?lang=en` a duplicate that points back at the bare URL.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('home', ['lang' => 'nl']) : route('home');
    $description = __('DipCatch watches the price of the groceries and products you buy anyway across Dutch supermarkets and webshops, compares shops on unit price, and alerts you when one drops.');
    $h1 = __('Same product, every supermarket, one alert.');
    $sub = __('DipCatch watches the groceries you buy anyway across AH, Jumbo, Dirk, Lidl, Aldi and more, compares them on price per kilo, and tells you when one drops.');
    $authed = auth()->check();
    $primaryHref = $authed ? url('/app') : route('register');
    $headerLabel = $authed ? __('Open app') : __('Create account');
    $headerLabelShort = $authed ? __('Open app') : __('Sign up');
    $contactEmail = config('site.contact_email');
    $steps = [
        ['n' => '01', 'title' => __('Paste a product link'), 'body' => __('DipCatch reads the title, image, price and pack size from the page. You install nothing.')],
        ['n' => '02', 'title' => __('Add it from other shops'), 'body' => __('Track the same item at other shops and DipCatch shows the cheapest one, with unit prices (€/kg, €/l) so different pack sizes compare fairly.')],
        ['n' => '03', 'title' => __('You get the dip'), 'body' => __('Prices are re-checked automatically. When one falls past your threshold you hear about it: a daily email digest, a note under the bell in the app, or a browser push if you turn that on.')],
    ];
    $supportedShops = \App\Support\SupportedShops::rows();
    $money = static fn (string $amount): string => \App\Support\MoneyFormatter::format($amount, 'EUR');
    $tracked = [
        [
            'icon' => '🥔',
            'name' => 'Lay’s Naturel 200 g',
            'shop' => 'ah.nl',
            'old' => $money('2.19'),
            'new' => __(':price (bonus)', ['price' => $money('1.69')]),
            'unit' => __(':price /kg · cheapest of :count shops', ['price' => $money('8.45'), 'count' => 4]),
        ],
        [
            'icon' => '🧀',
            'name' => 'Beemster Extra Belegen 48+ 150 g',
            'shop' => 'dirk.nl',
            'old' => $money('3.49'),
            'new' => $money('1.69'),
            'unit' => __(':price /kg · cheapest of :count shops', ['price' => $money('11.27'), 'count' => 3]),
        ],
        [
            'icon' => '🧻',
            'name' => 'Page toiletpapier 24 rollen',
            'shop' => 'jumbo.com',
            'old' => $money('12.99'),
            'new' => $money('9.99'),
            'unit' => __(':price /stuk', ['price' => $money('0.42')]),
        ],
    ];
    $faq = [
        ['q' => __('Which shops work?'), 'a' => __('DipCatch has built-in support for AH, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz, Vomar, bol.com, Amazon.nl and Zooplus, including AH bonus and Dirk promo prices. Many other webshops publish their product data in a form DipCatch can read. Shops that block bots or only load prices with JavaScript may not work, and you see the result before you confirm.')],
        ['q' => __('How often are prices checked?'), 'a' => __('A shop is checked the moment you add it or change its link. After that DipCatch re-checks it about every :hours hours, give or take half an hour.', ['hours' => config('dipcatch.recheck.interval_hours', 6)])],
        ['q' => __('Is it free?'), 'a' => __('Yes, during the beta. You do not need a card and there is no trial that runs out.')],
        ['q' => __('Do I need an extension or app?'), 'a' => __('No. You paste a link in your browser. Alerts arrive as a daily email digest, under the bell in the app, or as a browser push if you turn that on.')],
        ['q' => __('Can I compare different pack sizes?'), 'a' => __('Yes. When DipCatch can read the pack size, it shows a price per kilo, litre or piece next to that shop, so a 200 g and a 370 g bag compare fairly.')],
        ['q' => __('Can I share a comparison?'), 'a' => __('Yes. Every product has an optional public page with the current price per shop and, where there is history, a chart of the cheapest price over the last 90 days. Anyone with the link can view it. It shows nothing about your account.')],
    ];
    $mockLabel = __('Example alerts: :items', [
        'items' => implode('. ', array_map(
            static fn (array $p): string => __(':product at :shop: from :old to :new, :unit', [
                'product' => $p['name'],
                'shop' => $p['shop'],
                'old' => $p['old'],
                'new' => $p['new'],
                'unit' => $p['unit'],
            ]),
            $tracked,
        )),
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', ['title' => __('Supermarket price alerts for the Netherlands')])
        <meta name="description" content="{{ $description }}">
        <link rel="canonical" href="{{ $canonical }}">
        <link rel="alternate" hreflang="en" href="{{ route('home') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('home', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('home') }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') . ' — ' . $h1 }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:url" content="{{ $canonical }}">
        <meta property="og:locale" content="{{ $locale === 'nl' ? 'nl_NL' : 'en_US' }}">
        <meta property="og:image" content="{{ asset('apple-touch-icon.png') }}">
        <meta name="twitter:card" content="summary">
        {{ \App\Support\JsonLd::script([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(static fn (array $item): array => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $item['a'],
                ],
            ], $faq),
        ]) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="isolate flex min-h-dvh flex-col">
            <div class="relative flex flex-1 flex-col overflow-hidden">
                <div aria-hidden="true" class="pointer-events-none absolute -top-40 -left-40 size-[28rem] rounded-full bg-amber-200/40 blur-3xl dark:hidden"></div>
                <div aria-hidden="true" class="pointer-events-none absolute right-0 -bottom-32 size-[28rem] rounded-full bg-rose-200/40 blur-3xl dark:hidden"></div>

                <header class="relative mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-6 lg:px-8">
                    <a href="{{ route('home', $langQuery) }}" aria-label="{{ __('Homepage') }}" class="flex items-center gap-2 font-semibold">
                        <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                            <x-app-logo-icon class="size-7" />
                        </span>
                        <span class="hidden sm:inline">{{ config('app.name') }}</span>
                    </a>
                    <nav class="flex items-center gap-2 sm:gap-3">
                        <div class="flex items-center rounded-full bg-white/80 p-0.5 text-[0.6875rem] font-semibold ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/80 dark:ring-zinc-800" role="group" aria-label="{{ __('Language') }}">
                            <a href="{{ route('home', ['lang' => 'nl']) }}" hreflang="nl" lang="nl" aria-label="Nederlands" @if ($locale === 'nl') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'nl', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'nl'])>nl</a>
                            <a href="{{ route('home', ['lang' => 'en']) }}" hreflang="en" lang="en" aria-label="English" @if ($locale === 'en') aria-current="true" @endif @class(['rounded-full px-2 py-1 uppercase', 'hidden sm:inline-block bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === 'en', 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== 'en'])>en</a>
                        </div>
                        <x-appearance-toggle />
                        <a href="{{ $primaryHref }}" class="whitespace-nowrap rounded-full bg-white/80 px-3 py-1.5 text-sm sm:px-4 font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white dark:bg-zinc-900/80 dark:text-zinc-200 dark:ring-zinc-800 dark:hover:bg-zinc-900"><span class="sm:hidden">{{ $headerLabelShort }}</span><span class="hidden sm:inline">{{ $headerLabel }}</span></a>
                    </nav>
                </header>

                <main class="relative mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <section class="grid items-center gap-12 py-16 sm:py-24 lg:grid-cols-12 lg:gap-12">
                        <div class="lg:col-span-7">
                            <span class="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:text-zinc-300 dark:ring-zinc-800">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                {{ __('Open beta') }}
                            </span>
                            <h1 class="mt-6 max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-6xl">{{ $h1 }}</h1>
                            <p class="mt-6 max-w-[48ch] text-lg text-pretty text-zinc-600 sm:text-xl dark:text-zinc-400">{{ $sub }}</p>
                            @auth
                                <div class="mt-10 flex flex-wrap items-center gap-3">
                                    <a href="{{ url('/app') }}" class="inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200">{{ __('Open dashboard') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                                </div>
                            @else
                                <div class="mt-10">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <a href="{{ route('register') }}" class="inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                                    </div>
                                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('Free during the beta. You’ll get a verification email first.') }}
                                        {{ __('Already have an account?') }}
                                        <a href="{{ route('login') }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Sign in') }}</a>
                                    </p>
                                </div>
                            @endauth

                            <div class="mt-12">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Works with') }}</p>
                                <ul class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($supportedShops as $shop)
                                        <li @class(['items-center gap-2 rounded-full bg-white/80 px-3 py-1.5 text-sm text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:text-zinc-200 dark:ring-zinc-800', 'inline-flex' => $loop->index < 8, 'hidden sm:inline-flex' => $loop->index >= 8])>
                                            <img src="{{ $shop['favicon'] }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                                            {{ $shop['host'] }}
                                        </li>
                                    @endforeach
                                    @if (count($supportedShops) > 8)
                                        <li class="inline-flex items-center rounded-full px-3 py-1.5 text-sm text-zinc-500 sm:hidden dark:text-zinc-400">{{ __('+:count more', ['count' => count($supportedShops) - 8]) }}</li>
                                    @endif
                                    <li class="inline-flex items-center px-2 py-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('+ many other webshops') }}</li>
                                </ul>
                            </div>
                        </div>

                        <div class="lg:col-span-5">
                            <div class="relative mx-auto w-64 lg:w-72 lg:rotate-3" role="img" aria-label="{{ $mockLabel }}">
                                <div aria-hidden="true" class="rounded-[2.5rem] bg-zinc-900 p-3 shadow-2xl ring-1 ring-zinc-800 dark:shadow-none">
                                    <div class="rounded-[2rem] bg-linear-to-br from-amber-100 via-rose-100 to-violet-100 p-4 pt-12 dark:from-zinc-800 dark:via-zinc-800 dark:to-zinc-900">
                                        <div class="absolute top-5 right-0 left-0 flex items-center justify-between px-8 font-semibold text-zinc-900 tabular-nums dark:text-zinc-100">
                                            <span class="text-xs">9:41</span>
                                            <span class="size-3 rounded-full bg-zinc-900 dark:bg-zinc-100"></span>
                                            <span class="text-xs">DipCatch</span>
                                        </div>
                                        <div class="space-y-2.5">
                                            @foreach ($tracked as $i => $p)
                                                <div class="rounded-2xl bg-white/95 p-3 shadow-sm ring-1 ring-zinc-200 backdrop-blur-sm @if ($i === 0) ring-emerald-300 dark:ring-emerald-800 @endif dark:bg-zinc-900/95 dark:shadow-none dark:ring-zinc-800">
                                                    <div class="flex items-start gap-2.5">
                                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-lg dark:bg-emerald-950/60">{{ $p['icon'] }}</span>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex items-center gap-1.5">
                                                                <img src="{{ \App\Support\Favicon::url($p['shop'], 32) }}" alt="" loading="lazy" class="size-3.5 shrink-0 rounded-sm" />
                                                                <p class="text-[0.625rem] font-semibold text-zinc-500 dark:text-zinc-400">{{ $p['shop'] }}</p>
                                                            </div>
                                                            <p class="mt-0.5 text-xs">{{ $p['name'] }}</p>
                                                            <p class="text-xs text-emerald-600 tabular-nums dark:text-emerald-400">{{ __(':old → :new', ['old' => $p['old'], 'new' => $p['new']]) }}</p>
                                                            <p class="text-[0.625rem] text-zinc-500 tabular-nums dark:text-zinc-400">{{ $p['unit'] }}</p>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                                <div aria-hidden="true" class="absolute -top-6 -left-8 size-3 rounded-full bg-amber-400"></div>
                                <div aria-hidden="true" class="absolute -right-6 bottom-12 size-2 rounded-full bg-rose-400"></div>
                            </div>
                        </div>
                    </section>

                    <section id="how-it-works" class="py-20">
                        <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('How it works') }}</h2>
                        <p class="mt-4 max-w-[56ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __('Add a product and set a threshold. DipCatch does the checking, so you do not need a browser extension.') }}</p>
                        <dl class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($steps as $step)
                                <div class="rounded-2xl bg-white/80 p-6 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:ring-zinc-800">
                                    <span class="inline-flex size-9 items-center justify-center rounded-full bg-amber-100 font-mono text-sm font-semibold text-amber-800 tabular-nums dark:bg-amber-950/60 dark:text-amber-300">{{ $step['n'] }}</span>
                                    <dt class="mt-4 text-base font-semibold">{{ $step['title'] }}</dt>
                                    <dd class="mt-2 text-sm text-pretty text-zinc-600 dark:text-zinc-400">{{ $step['body'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>

                    <section id="faq" class="py-20">
                        <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Frequently asked questions') }}</h2>
                        <div class="mt-10 grid items-start gap-4 sm:grid-cols-2">
                            @foreach ($faq as $item)
                                <details class="group rounded-2xl bg-white/80 p-2 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:ring-zinc-800">
                                    <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-4 py-3 text-base font-semibold select-none [&::-webkit-details-marker]:hidden">
                                        <span>{{ $item['q'] }}</span>
                                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="size-5 shrink-0 text-zinc-500 transition-transform group-open:rotate-180 dark:text-zinc-400">
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                        </svg>
                                    </summary>
                                    <p class="px-4 pb-3 text-sm text-pretty text-zinc-600 dark:text-zinc-400">{{ $item['a'] }}</p>
                                </details>
                            @endforeach
                        </div>
                    </section>

                    @guest
                        <section class="pb-20">
                            <div class="rounded-3xl bg-zinc-900 px-6 py-10 text-center text-white ring-1 ring-zinc-800 sm:px-12 sm:py-12 dark:bg-zinc-900/80">
                                <h2 class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">{{ __('Stop checking prices by hand.') }}</h2>
                                <p class="mx-auto mt-3 max-w-[48ch] text-pretty text-zinc-300">{{ __('Add the products you buy anyway and let DipCatch tell you where they are cheapest this week.') }}</p>
                                <a href="{{ route('register') }}" class="mt-8 inline-flex items-center rounded-full bg-white px-5 py-2.5 text-sm font-medium text-zinc-900 shadow-md hover:bg-zinc-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                            </div>
                        </section>
                    @endguest
                </main>

                <footer class="relative mx-auto mt-auto w-full max-w-7xl px-6 pb-10 lg:px-8">
                    <div class="flex flex-col items-center justify-between gap-3 border-t border-zinc-200 pt-6 text-sm text-zinc-500 sm:flex-row dark:border-zinc-800 dark:text-zinc-400">
                        <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
                        <nav class="flex items-center gap-5" aria-label="{{ __('Footer') }}">
                            <a href="{{ route('privacy', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Privacy') }}</a>
                            @if (filled($contactEmail))
                                <a href="mailto:{{ $contactEmail }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Contact') }}</a>
                            @endif
                        </nav>
                    </div>
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
