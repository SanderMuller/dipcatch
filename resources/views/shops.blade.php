@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('shops', ['lang' => 'nl']) : route('shops');
    $contactEmail = config('site.contact_email');
    $description = __('Every shop we know well, with a page of its own. Each one says what DipCatch can see there: the price, how much is in the pack, and how long an offer lasts.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
    <head>
        @include('partials.head', [
            'title' => __('Supported shops'),
            'description' => $description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ route('shops') }}">
        <link rel="alternate" hreflang="nl" href="{{ route('shops', ['lang' => 'nl']) }}">
        <link rel="alternate" hreflang="x-default" href="{{ route('shops') }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::shopsHub($shops, $canonical, $description)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />

            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-12 pb-20 lg:px-8">
                <h1 class="max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ __('Supported shops') }}</h1>
                <p class="mt-5 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
                <p class="mt-4 max-w-[64ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">
                    {{ __('Paste a product link from almost any webshop and it works. The shops on this page get extra attention, because their pages are the trickiest to read.') }}
                    <x-shop-request-link class="font-medium text-ink hover:text-brand" />
                </p>

                <ul role="list" class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($shops as $shop)
                        <li>
                            <a href="{{ route('shop', [...$langQuery, 'slug' => $shop->slug]) }}" class="flex h-full flex-col rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm hover:bg-paper hover:ring-zinc-300 dark:hover:ring-zinc-700">
                                <span class="flex items-center gap-2.5">
                                    <span style="background-image: url('{{ $shop->favicon() }}')" class="size-6 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                    <span class="text-lg font-semibold">{{ $shop->name }}</span>
                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $shop->host }}</span>
                                </span>
                                <span class="mt-3 text-sm text-pretty text-zinc-600 dark:text-zinc-400">{{ $shop->facts[0] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @php($unsupported = \App\Support\SupportedShops::unsupported())
                @if ($unsupported !== [])
                    <section class="mt-16" data-test="unsupported-shops">
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Shops we cannot read') }}</h2>
                        <p class="mt-3 max-w-[64ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __('Some of these shops refuse the requests DipCatch makes. Others only load their price with a script after the page opens. Either way, DipCatch cannot read their prices. You can still keep a link to one of them on a product, and DipCatch keeps trying that page.') }}</p>
                        <ul role="list" class="mt-5 flex flex-wrap gap-2">
                            @foreach ($unsupported as $shop)
                                <li class="inline-flex items-center gap-2 rounded-full bg-paper/60 py-1.5 pr-3 pl-1.5 text-sm text-zinc-600 ring-1 ring-line dark:text-zinc-300">
                                    <span style="background-image: url('{{ $shop['favicon'] }}')" class="size-4 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat grayscale"></span>
                                    <span>{{ $shop['name'] }}</span>
                                    <span class="text-zinc-400 dark:text-zinc-500">{{ $shop['host'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

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
