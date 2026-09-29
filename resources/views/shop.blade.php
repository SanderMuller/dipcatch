@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, exactly as on the homepage, pricing and the use-case pages.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? $shop->url('nl') : $shop->url();
    $contactEmail = config('site.contact_email');
    $related = $shop->relatedUseCases();
    $others = collect(\App\Support\SupportedShops::rows())->reject(fn (array $row): bool => $row['host'] === $shop->host)->take(6);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => $shop->heading,
            'description' => $shop->description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ $shop->url() }}">
        <link rel="alternate" hreflang="nl" href="{{ $shop->url('nl') }}">
        <link rel="alternate" hreflang="x-default" href="{{ $shop->url() }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::shop($shop, $canonical)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />

            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-12 pb-20 lg:px-8">
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    <a href="{{ route('shops', $langQuery) }}" class="hover:text-brand">{{ __('Supported shops') }}</a>
                </p>

                <div class="mt-3 flex items-center gap-3">
                    <span style="background-image: url('{{ $shop->favicon() }}')" class="size-8 shrink-0 rounded-md bg-white bg-contain bg-center bg-no-repeat ring-1 ring-line"></span>
                    <h1 class="max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $shop->heading }}</h1>
                </div>

                <p class="mt-5 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $shop->intro }}</p>

                <div class="mt-8">
                    <a href="{{ route('register') }}" class="inline-flex items-center rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                </div>

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('What DipCatch reads at :shop', ['shop' => $shop->name]) }}</h2>
                    <ul role="list" class="mt-5 max-w-[64ch] space-y-3">
                        @foreach ($shop->facts as $fact)
                            <li class="flex items-start gap-3 text-base text-pretty text-zinc-700 dark:text-zinc-300">
                                <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-brand"></span>
                                <span>{{ $fact }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('How to track a product from :shop', ['shop' => $shop->name]) }}</h2>
                    <ol role="list" class="mt-6 grid gap-4 sm:grid-cols-3">
                        @foreach ([
                            ['n' => '01', 'body' => __('Copy the link to the product page at :shop.', ['shop' => $shop->name])],
                            ['n' => '02', 'body' => __('Paste it into DipCatch. You see the name, the price and the pack size before anything is saved.')],
                            ['n' => '03', 'body' => __('Add the same product at another shop, say what you want to pay, and wait to hear from us.')],
                        ] as $step)
                            <li class="rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm">
                                <span aria-hidden="true" class="inline-flex rounded-lg bg-soft-yellow px-2 py-1 text-sm font-semibold tabular-nums">{{ $step['n'] }}</span>
                                <p class="mt-4 text-sm text-pretty text-zinc-600 dark:text-zinc-400">{{ $step['body'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                </section>

                @if ($related !== [])
                    <section class="mt-16">
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('What people track at :shop', ['shop' => $shop->name]) }}</h2>
                        <ul role="list" class="mt-5 flex flex-wrap gap-2">
                            @foreach ($related as $useCase)
                                <li>
                                    <a href="{{ route('use-case', [...$langQuery, 'slug' => $useCase->slug]) }}" class="inline-flex items-center rounded-full bg-paper/80 px-3 py-1.5 text-sm text-zinc-700 shadow-xs ring-1 ring-line backdrop-blur-sm hover:bg-paper dark:text-zinc-200 dark:shadow-none">{{ $useCase->heading }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Common questions') }}</h2>
                    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
                        @foreach ($shop->faq as $item)
                            <div class="rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm">
                                <h3 class="font-semibold">{{ $item['q'] }}</h3>
                                <p class="mt-2 text-pretty text-zinc-600 dark:text-zinc-300">{{ $item['a'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-6 max-w-[64ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">
                        {{ __('Paste a product link from any other shop as well. Most shops work. The ones named here just get extra attention.') }}
                        <x-shop-request-link :host="$shop->host" class="font-medium text-ink hover:text-brand" />
                    </p>
                </section>

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Compare :shop with', ['shop' => $shop->name]) }}</h2>
                    <ul role="list" class="mt-5 flex flex-wrap gap-2">
                        @foreach ($others as $other)
                            <li>
                                <a href="{{ route('shop', [...$langQuery, 'slug' => $other['slug']]) }}" class="inline-flex items-center gap-2 rounded-full bg-paper/80 py-1.5 pr-3 pl-1.5 text-sm text-zinc-700 shadow-xs ring-1 ring-line backdrop-blur-sm hover:bg-paper dark:text-zinc-200 dark:shadow-none">
                                    <span style="background-image: url('{{ $other['favicon'] }}')" class="size-4 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                    <span>{{ $other['name'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section class="mt-16 flex flex-col gap-6 rounded-2xl bg-soft-yellow/70 p-8 ring-1 ring-line backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between sm:gap-10 sm:p-10 dark:bg-paper">
                    <h2 class="max-w-[30ch] text-2xl font-semibold tracking-tight text-balance">{{ __('Stop checking prices by hand.') }}</h2>
                    <a href="{{ route('register') }}" class="inline-flex shrink-0 items-center self-start sm:self-auto rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                </section>
            </main>

            <footer class="mx-auto w-full max-w-app px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="$contactEmail" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
