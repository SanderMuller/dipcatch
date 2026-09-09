@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? route('shops', ['lang' => 'nl']) : route('shops');
    $contactEmail = config('site.contact_email');
    $description = __('Every shop DipCatch has a reader for, and what it can see at each one: the price, the pack size, and the offer window where the shop states it.');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
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
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header width="max-w-4xl" />

            <main class="mx-auto w-full max-w-4xl flex-1 px-6 pt-12 pb-20 lg:px-8">
                <h1 class="max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ __('Supported shops') }}</h1>
                <p class="mt-5 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
                <p class="mt-4 max-w-[64ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">
                    {{ __('Many other webshops publish their product data in a form DipCatch can read as well. These are the ones with a reader written for them, so they are the ones that keep working when a page changes.') }}
                </p>

                <ul role="list" class="mt-10 grid gap-4 sm:grid-cols-2">
                    @foreach ($shops as $shop)
                        <li>
                            <a href="{{ route('shop', [...$langQuery, 'slug' => $shop->slug]) }}" class="flex h-full flex-col rounded-2xl bg-white/80 p-6 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white dark:bg-zinc-900/60 dark:ring-zinc-800 dark:hover:bg-zinc-900">
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

                <section class="mt-16 flex flex-col gap-5 rounded-3xl bg-white/80 p-8 ring-1 ring-zinc-200 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between dark:bg-zinc-900/60 dark:ring-zinc-800">
                    <h2 class="max-w-[30ch] text-2xl font-semibold tracking-tight text-balance">{{ __('Stop checking prices by hand.') }}</h2>
                    <a href="{{ route('register') }}" class="inline-flex shrink-0 items-center self-start rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 sm:self-auto dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                </section>
            </main>

            <footer class="mx-auto w-full max-w-4xl px-6 pb-10 lg:px-8">
                <x-marketing-footer-links :lang-query="$langQuery" :contact-email="$contactEmail" />
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
