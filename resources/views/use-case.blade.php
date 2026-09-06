@php
    // Marketing locale: `?lang=nl|en` only, English otherwise (MarketingLocale).
    // The bare URL is the canonical English page, `?lang=nl` the canonical
    // Dutch one, exactly as on the homepage, pricing and privacy.
    $locale = app()->getLocale();
    $requestedLang = \App\Http\Middleware\MarketingLocale::requested(request());
    $langQuery = $requestedLang === null ? [] : ['lang' => $requestedLang];
    $canonical = $locale === 'nl' ? $useCase->url('nl') : $useCase->url();
    $contactEmail = config('site.contact_email');
    $shops = $useCase->shops();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => $useCase->heading,
            'description' => $useCase->description,
            'canonical' => $canonical,
        ])
        <link rel="alternate" hreflang="en" href="{{ $useCase->url() }}">
        <link rel="alternate" hreflang="nl" href="{{ $useCase->url('nl') }}">
        <link rel="alternate" hreflang="x-default" href="{{ $useCase->url() }}">
        {{ \App\Support\JsonLd::script(\App\Support\StructuredData::useCase($useCase, $canonical)) }}
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header width="max-w-4xl" />

            <main class="mx-auto w-full max-w-4xl flex-1 px-6 pt-12 pb-20 lg:px-8">
                <h1 class="max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $useCase->heading }}</h1>
                <p class="mt-5 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $useCase->intro }}</p>

                <div class="mt-8">
                    <a href="{{ route('register') }}" class="inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                </div>

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('What this looks like') }}</h2>
                    <p class="mt-4 max-w-[64ch] text-base text-pretty text-zinc-700 dark:text-zinc-300">{{ $useCase->example }}</p>
                </section>

                @if ($shops !== [])
                    <section class="mt-16">
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Which shops this works with') }}</h2>
                        <ul class="mt-5 flex flex-wrap gap-2">
                            @foreach ($shops as $shop)
                                <li class="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1.5 text-sm text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:text-zinc-200 dark:ring-zinc-800">
                                    <span style="background-image: url('{{ $shop['favicon'] }}')" class="size-4 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                    <span>{{ $shop['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Frequently asked questions') }}</h2>
                    <div class="mt-6 space-y-6">
                        @foreach ($useCase->faq as $item)
                            <div class="rounded-2xl bg-white/80 p-6 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:ring-zinc-800">
                                <h3 class="font-semibold">{{ $item['q'] }}</h3>
                                <p class="mt-2 text-pretty text-zinc-600 dark:text-zinc-300">{{ $item['a'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

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
