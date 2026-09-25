@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, and `?lang=en` a duplicate that points back at the bare URL.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('home', ['lang' => 'nl']) : route('home');
    $description = __('DipCatch follows the price of the things you buy again and again, at Dutch supermarkets and webshops. It works out what a kilo or a piece really costs, and says when one shop drops below the rest.');
    $h1 = __('Same product, every shop, one alert.');
    $sub = __('You already buy coffee, cat food, skincare and vacuum filters. DipCatch keeps an eye on them at Albert Heijn, Jumbo, bol.com, Zooplus and more. It compares the price per kilo or per piece, and tells you when one gets cheaper.');
    $authed = auth()->check();
    $primaryHref = $authed ? url('/app') : route('register');
    $headerLabel = $authed ? __('Open app') : __('Create account');
    $headerLabelShort = $authed ? __('Open app') : __('Sign up');
    $contactEmail = config('site.contact_email');
    $steps = [
        ['n' => '01', 'icon' => 'link', 'title' => __('Paste a product link'), 'body' => __('DipCatch picks up the name, the photo, the price and the pack size by itself. You install nothing.')],
        ['n' => '02', 'icon' => 'magnifying-glass', 'title' => __('Add it from other shops'), 'body' => __('Add the same thing at other shops. DipCatch shows which one is cheapest and works out the price per kilo or per litre. So you can see whether the big pack really is the better deal.')],
        ['n' => '03', 'icon' => 'bell', 'title' => __('You hear about it'), 'body' => __('Say what a good price is for you. We check the shops and let you know the moment one of them goes below it. One email a day, a note in the app, or a message in your browser.')],
    ];
    $supportedShops = \App\Support\SupportedShops::homepage();
    $money = static fn (string $amount): string => \App\Support\MoneyFormatter::format($amount, 'EUR');
    $drop = static fn (string $old, string $new): int => (int) round((1 - (float) $new / (float) $old) * 100);
    $tracked = [
        [
            'image' => 'product-chips.webp',
            'name' => 'Lay’s Naturel 200 g',
            'shop' => 'ah.nl',
            'old' => $money('2.19'),
            'new' => __(':price (bonus)', ['price' => $money('1.69')]),
            'unit' => __(':price /kg · cheapest of :count shops', ['price' => $money('8.45'), 'count' => 4]),
            'drop' => $drop('2.19', '1.69'),
            'spark' => ['line' => 'stroke-savings', 'area' => 'fill-savings/10', 'points' => '0,6 18,8 34,5 52,9 70,8 86,22 100,24'],
        ],
        [
            'image' => 'product-cheese.webp',
            'name' => 'Beemster Extra Belegen 48+ 150 g',
            'shop' => 'dirk.nl',
            'old' => $money('3.49'),
            'new' => $money('1.69'),
            'unit' => __(':price /kg · cheapest of :count shops', ['price' => $money('11.27'), 'count' => 3]),
            'drop' => $drop('3.49', '1.69'),
            'spark' => ['line' => 'stroke-chart', 'area' => 'fill-chart/10', 'points' => '0,4 16,6 32,5 48,10 64,14 82,20 100,26'],
        ],
        [
            'image' => 'product-toilet-paper.webp',
            'name' => 'Page toiletpapier 24 rollen',
            'shop' => 'jumbo.com',
            'old' => $money('12.99'),
            'new' => $money('9.99'),
            'unit' => __(':price /stuk', ['price' => $money('0.42')]),
            'drop' => $drop('12.99', '9.99'),
            'spark' => ['line' => 'stroke-brand', 'area' => 'fill-brand/10', 'points' => '0,8 20,7 36,10 54,8 72,12 88,18 100,20'],
        ],
    ];
    $freeProducts = \App\Billing\Entitlements::of(\App\Billing\Plan::Free)->maxProducts();
    $faq = [
        ['q' => __('Which shops work?'), 'a' => __('Paste a product link. Most webshops work, as long as the price is on the page. These shops are the surest bet: Albert Heijn, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz, Vomar, bol.com, Amazon, Zooplus, Bitiba, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, Etos, The Ordinary, Lookfantastic, Cult Beauty, Ulta and Walmart. Offer prices such as AH Bonus and the Dirk deals come through there too. A few shops hide their prices from us, and those will not work. You always see what we found before anything is saved.')],
        ['q' => __('What if my shop is not listed?'), 'a' => __('Paste it anyway. The shops named on this site are not the only ones that work. If the price does not come through, send us the link and we will look at it.')],
        ['q' => __('How often are prices checked?'), 'a' => __('A shop is checked the moment you add it or change the link. After that DipCatch looks again about every :hours hours.', ['hours' => config('dipcatch.recheck.interval_hours', 6)])],
        ['q' => __('Is it free?'), 'a' => $freeProducts === null
            ? __('Yes. The free plan has no product limit, you do not need a card, and there is no trial that runs out.')
            : __('Yes, for your first :count products. You do not need a card, and there is no trial that runs out. Pro lifts the limit when you want more.', ['count' => $freeProducts])],
        ['q' => __('Do I need an extension or app?'), 'a' => __('No. You paste a link in your browser. You hear from us in one email a day, under the bell in the app, or in your browser if you switch that on.')],
        ['q' => __('Can I use DipCatch in ChatGPT or Claude?'), 'a' => __('Yes. Ask it to follow something new, to add another shop, to change the price you want, or to show you how a price moved. Before it saves anything, it shows you what it found: the name and the price. That way you can spot a link that points at the wrong pack. Connect DipCatch once on the Connections page in your account.')],
        ['q' => __('Can I compare different pack sizes?'), 'a' => __('Yes. When DipCatch can read how much is in the pack, it shows a price per kilo, litre or piece next to that shop. A 200 g bag and a 370 g bag then compare fairly.')],
        ['q' => __('Can I share a comparison?'), 'a' => __('Yes. Every product can get its own public page with the price at each shop. Once there is enough history, it also shows a graph of the lowest price over the last 90 days. Anyone with the link can look at it, and it says nothing about you or your account.')],
        ['q' => __('How do I know when a product is cheaper somewhere else?'), 'a' => __('Paste the link from the shop you buy at now, then add the same product at the others. DipCatch tells you when the cheapest one goes below the price you set.')],
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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Price alerts for the things you buy anyway'),
            'description' => $description,
            'canonical' => $canonical,
            'ogTitle' => config('app.name') . ' — ' . $h1,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('home') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('home', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('home') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::home($faq, $canonical, $description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="isolate flex min-h-dvh flex-col">
            {{-- Outside the overflow-hidden wrapper below: an ancestor that
                 hides overflow turns off `position: sticky`. --}}
            <x-marketing-header />

            <div class="relative flex flex-1 flex-col overflow-hidden">
                <div aria-hidden="true" class="pointer-events-none absolute -top-40 -left-40 size-[28rem] rounded-full bg-soft-yellow/60 blur-3xl dark:hidden"></div>
                <div aria-hidden="true" class="pointer-events-none absolute top-24 right-0 size-[32rem] rounded-full bg-soft-yellow/50 blur-3xl dark:hidden"></div>
                <div aria-hidden="true" class="pointer-events-none absolute right-0 -bottom-32 size-[28rem] rounded-full bg-soft-blush/70 blur-3xl dark:hidden"></div>


                <main class="relative mx-auto w-full max-w-app px-6 lg:px-8">
                    <section class="grid grid-cols-1 items-center gap-12 py-16 sm:py-24 lg:grid-cols-12">
                        <div class="lg:col-span-7">
                            <span class="inline-flex items-center gap-2 rounded-full bg-paper/80 px-3 py-1 text-xs font-medium text-zinc-700 ring-1 ring-line backdrop-blur-sm dark:text-zinc-300">
                                <span class="size-1.5 rounded-full bg-savings"></span>
                                {{ __('Open beta') }}
                            </span>
                            <h1 class="mt-6 max-w-[20ch] text-5xl font-semibold tracking-tighter text-balance sm:text-7xl">{{ $h1 }}</h1>
                            <p class="mt-6 max-w-[48ch] text-lg text-pretty text-zinc-600 dark:text-zinc-400">{{ $sub }}</p>
                            @auth
                                <div class="mt-10 flex flex-wrap items-center gap-3">
                                    <a href="{{ url('/app') }}" class="inline-flex items-center rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">{{ __('Open dashboard') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                                </div>
                            @else
                                <div class="mt-10">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <a href="{{ route('register') }}" class="inline-flex items-center rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                                    </div>
                                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('Free while we are in beta. We send one email first, to check the address is yours.') }}
                                        {{ __('Already have an account?') }}
                                        <a href="{{ route('login') }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ __('Sign in') }}</a>
                                    </p>
                                </div>
                            @endauth

                            <div class="mt-12">
                                <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Works with') }}</p>
                                <ul class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($supportedShops as $shop)
                                        <li @class(['items-center', 'inline-flex' => $loop->index < 8, 'hidden sm:inline-flex' => $loop->index >= 8])>
                                            {{-- Linked, not decorative: each shop has a page of its own, and
                                                 this row is where a reader looks for it. --}}
                                            <a href="{{ route('shop', [...$langQuery, 'slug' => $shop['slug']]) }}" class="inline-flex items-center gap-2 rounded-full bg-paper/80 py-1.5 pr-3 pl-1.5 text-sm text-zinc-700 shadow-xs ring-1 ring-line backdrop-blur-sm hover:bg-paper dark:text-zinc-200 dark:shadow-none">
                                                {{-- Background image, not an <img>: the edge Markdown twin emits an
                                                     image reference even for an empty alt. --}}
                                                <span style="background-image: url('{{ $shop['favicon'] }}')" class="size-4 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                                {{-- The brand name, not the domain: a shopper looks for
                                                     "Albert Heijn", not "ah.nl". The domain stays in the
                                                     title so the exact site is still one hover away. --}}
                                                <span title="{{ $shop['host'] }}">{{ $shop['name'] }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                    <li class="inline-flex items-center px-2 py-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                                        <a href="{{ route('shops', $langQuery) }}" class="underline underline-offset-4 hover:text-brand">{{ __('and many other webshops') }}</a>
                                    </li>
                                </ul>
                                <p class="mt-3 max-w-[48ch] text-sm text-pretty text-zinc-500 dark:text-zinc-400">
                                    {{ __('Paste a link from almost any webshop and it works. These are the ones we check most often.') }}
                                    <x-shop-request-link class="hover:text-zinc-700 dark:hover:text-zinc-300" />
                                </p>
                            </div>
                        </div>

                        <div class="lg:col-span-5">
                            {{-- The annotation is English handwriting, so it only joins the English page. --}}
                            <div @class(['relative mx-auto max-w-md', 'sm:mt-20' => $locale !== 'nl']) role="img" aria-label="{{ $mockLabel }}">
                                @if ($locale !== 'nl')
                                    {{-- Background image, not an <img>: the Markdown twin emits a
                                         reference for every image, decorative or not. --}}
                                    <span aria-hidden="true" style="background-image: url('{{ asset('images/home/track-it-save-on-it.webp') }}')" class="absolute -top-24 right-4 aspect-[440/289] w-44 bg-contain bg-no-repeat max-sm:hidden dark:hidden"></span>
                                @endif
                                <div aria-hidden="true" class="rounded-3xl bg-paper p-4 shadow-xl shadow-ink/5 ring-1 ring-ink/10 sm:p-5 dark:shadow-none">
                                    <p class="text-base font-semibold">{{ __('Tracked products') }}</p>
                                    <div class="mt-4 space-y-2.5">
                                        @foreach ($tracked as $p)
                                            <div class="flex items-center gap-3 rounded-2xl p-2.5 ring-1 ring-line">
                                                <span style="background-image: url('{{ asset('images/home/' . $p['image']) }}')" class="size-12 shrink-0 rounded-xl bg-canvas bg-size-[80%] bg-center bg-no-repeat ring-1 ring-line sm:size-14"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-medium text-pretty">{{ $p['name'] }}</p>
                                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                                        <p class="text-lg font-semibold text-brand tabular-nums">{{ $p['new'] }}</p>
                                                        <p class="inline-flex items-center gap-0.5 rounded-full bg-savings/10 py-0.5 pr-2 pl-1.5 text-xs font-medium text-savings-strong tabular-nums">
                                                            <flux:icon.arrow-down variant="micro" class="size-3.5 shrink-0" />
                                                            {{ $p['drop'] }}%
                                                        </p>
                                                    </div>
                                                    <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                                                        <span style="background-image: url('{{ \App\Support\Favicon::url($p['shop'], 32) }}')" class="size-3.5 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                                        <p class="truncate">{{ $p['shop'] }} · <s>{{ $p['old'] }}</s></p>
                                                    </div>
                                                    <p class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $p['unit'] }}</p>
                                                </div>
                                                <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="h-10 w-20 shrink-0 overflow-visible max-sm:hidden">
                                                    <polygon points="{{ $p['spark']['points'] }} 100,32 0,32" class="{{ $p['spark']['area'] }} stroke-none" />
                                                    <polyline points="{{ $p['spark']['points'] }}" fill="none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" class="{{ $p['spark']['line'] }}" />
                                                </svg>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="how-it-works" class="py-20">
                        <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('How a price alert works') }}</h2>
                        <p class="mt-4 max-w-[56ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __('Add a product and say what you want to pay. DipCatch does the checking, so you need nothing extra in your browser.') }}</p>
                        <ol class="mt-10 grid list-none gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($steps as $step)
                                <li class="rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm">
                                    <div aria-hidden="true" class="flex items-center gap-4">
                                        <span class="rounded-lg bg-soft-yellow px-2 py-1 text-sm font-semibold tabular-nums">{{ $step['n'] }}</span>
                                        <flux:icon :icon="$step['icon']" class="size-6 shrink-0" />
                                    </div>
                                    <h3 class="mt-4 text-lg font-semibold sm:text-base">{{ $step['title'] }}</h3>
                                    <p class="mt-2 text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-400">{{ $step['body'] }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </section>

                    @php($useCases = \App\Support\UseCases::all())

                    @if ($useCases !== [])
                        @php($useCaseImages = ['groceries' => 'category-groceries.webp', 'pet-food' => 'category-pet-food.webp', 'coffee' => 'category-coffee.webp', 'beauty' => 'category-beauty.webp', 'filters' => 'category-filters.webp', 'ask-your-assistant' => 'category-ask-your-assistant.webp'])
                        <section id="categories">
                            <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Price alerts for') }}</h2>
                            <nav class="mt-10" aria-label="{{ __('Price alerts by category') }}">
                                <ul role="list" class="grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-6">
                                    @foreach ($useCases as $useCase)
                                        <li>
                                            <a href="{{ route('use-case', [...$langQuery, 'slug' => $useCase->slug]) }}" class="flex h-full flex-col items-center gap-3 rounded-2xl bg-paper/80 p-4 text-center text-base font-medium text-balance ring-1 ring-line backdrop-blur-sm hover:bg-paper hover:ring-zinc-300 sm:text-sm dark:hover:ring-zinc-700">@isset($useCaseImages[$useCase->slug])<span style="background-image: url('{{ asset('images/home/' . $useCaseImages[$useCase->slug]) }}')" class="aspect-square w-full bg-contain bg-center bg-no-repeat"></span>@else<span class="flex aspect-square w-full items-center justify-center"><span style="background-image: url('{{ asset('images/dipcatch-logo.png') }}')" class="size-16 rounded-2xl bg-white bg-size-[80%] bg-center bg-no-repeat"></span></span>@endisset{{ $useCase->label }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </nav>
                        </section>
                    @endif

                    <section id="faq" class="py-20">
                        <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Common questions') }}</h2>
                        <div class="mt-10 grid items-start gap-4 sm:grid-cols-2">
                            @foreach (array_chunk($faq, (int) ceil(count($faq) / 2)) as $column)
                                <flux:accordion transition>
                                    @foreach ($column as $item)
                                        <flux:accordion.item :heading="$item['q']">
                                            {{ $item['a'] }}
                                        </flux:accordion.item>
                                    @endforeach
                                </flux:accordion>
                            @endforeach
                        </div>
                    </section>

                    @guest
                        <section class="pb-20">
                            {{-- The page is built from translucent cards over the
                                 gradient, headed left, with soft yellow as its accent.
                                 A solid dark slab with centred text was none of
                                 those things, so it read as a foreign block. --}}
                            <div class="flex flex-col gap-6 rounded-2xl bg-soft-yellow/70 p-8 ring-1 ring-line backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between sm:gap-10 sm:p-10 dark:bg-paper">
                                <div>
                                    <h2 class="max-w-[35ch] text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ __('Stop checking prices by hand.') }}</h2>
                                    <p class="mt-3 max-w-[48ch] text-pretty text-zinc-600 dark:text-zinc-300">{{ __('Add the products you buy anyway and let DipCatch tell you where they are cheapest this week.') }}</p>
                                </div>
                                <a href="{{ route('register') }}" class="inline-flex shrink-0 items-center self-start rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:self-auto sm:text-sm dark:shadow-none">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                            </div>
                        </section>
                    @endguest
                </main>

                <footer class="relative mx-auto mt-auto w-full max-w-app px-6 pb-10 lg:px-8">
                    <x-marketing-footer-links :lang-query="$langQuery" :contact-email="$contactEmail" />
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
