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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-canvas">
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
    <body class="min-h-dvh bg-linear-to-br from-canvas via-canvas to-soft-blush bg-fixed text-ink antialiased">
        <div class="flex min-h-dvh flex-col">
            <x-marketing-header />

            <main class="mx-auto w-full max-w-app flex-1 px-6 pt-12 pb-20 lg:px-8">
                {{-- The screenshot's chat is in English, so it only joins the English page. --}}
                @php($illustration = $locale !== 'nl' && $useCase->slug === 'ask-your-assistant' ? 'images/use-cases/ask-your-assistant-chat.webp' : null)

                <div @class(['grid grid-cols-1 items-center gap-12 lg:grid-cols-2' => $illustration !== null])>
                    <div>
                        <h1 class="max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $useCase->heading }}</h1>
                        <p class="mt-5 max-w-[60ch] text-lg text-pretty text-zinc-600 dark:text-zinc-300">{{ $useCase->intro }}</p>

                        <div class="mt-8">
                            <a href="{{ route('register') }}" class="inline-flex items-center rounded-full bg-ink px-5 py-3 text-base font-medium text-paper shadow-md hover:bg-ink/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand sm:text-sm dark:shadow-none">{{ __('Create a free account') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                        </div>

                        <section class="mt-16">
                            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('What this looks like') }}</h2>
                            <p class="mt-4 max-w-[64ch] text-base text-pretty text-zinc-700 dark:text-zinc-300">{{ $useCase->example }}</p>
                        </section>
                    </div>

                    @if ($illustration !== null)
                        <img src="{{ asset($illustration) }}" alt="{{ __('A chat with an assistant. Asked to track a product, it adds it to DipCatch, finds three more shops, and offers to set a price alert.') }}" width="1200" height="900" class="w-full rounded-[min(2vw,var(--radius-2xl))] shadow-xl shadow-ink/5 outline-1 -outline-offset-1 outline-black/5 dark:shadow-none">
                    @endif
                </div>

                @if ($useCase->tips !== [])
                    <section class="mt-16">
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Getting the most out of it') }}</h2>
                        <ul role="list" class="mt-5 max-w-[64ch] space-y-3">
                            @foreach ($useCase->tips as $tip)
                                <li class="flex items-start gap-3 text-base text-pretty text-zinc-700 dark:text-zinc-300">
                                    <span aria-hidden="true" class="mt-2 size-1.5 shrink-0 rounded-full bg-brand"></span>
                                    <span>{{ $tip }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="mt-16">
                    @if ($shops !== [])
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Shops we know well') }}</h2>
                        <ul role="list" class="mt-5 flex flex-wrap gap-2">
                            @foreach ($shops as $shop)
                                {{-- Linked: every one of these shops has a page of its own. --}}
                                <li>
                                    <a href="{{ route('shop', [...$langQuery, 'slug' => $shop['slug']]) }}" class="inline-flex items-center gap-2 rounded-full bg-paper/80 py-1.5 pr-3 pl-1.5 text-sm text-zinc-700 shadow-xs ring-1 ring-line backdrop-blur-sm hover:bg-paper dark:text-zinc-200 dark:shadow-none">
                                        <span style="background-image: url('{{ $shop['favicon'] }}')" class="size-4 shrink-0 rounded-sm bg-cover bg-center bg-no-repeat"></span>
                                        <span>{{ $shop['name'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p @class(['max-w-[64ch] text-base text-pretty text-zinc-600 dark:text-zinc-400', 'mt-5' => $shops !== []])>
                        {{ __('Paste a product link from any other shop as well. Most shops work. We only set a shop up ourselves when its page is too tricky to read otherwise.') }}
                        <x-shop-request-link class="font-medium text-ink hover:text-brand" />
                    </p>
                </section>

                <section class="mt-16">
                    <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ __('Common questions') }}</h2>
                    <div class="mt-6 grid items-start gap-6 lg:grid-cols-2">
                        @foreach ($useCase->faq as $item)
                            <div class="rounded-2xl bg-paper/80 p-6 ring-1 ring-line backdrop-blur-sm">
                                <h3 class="font-semibold">{{ $item['q'] }}</h3>
                                <p class="mt-2 text-pretty text-zinc-600 dark:text-zinc-300">{{ $item['a'] }}</p>
                            </div>
                        @endforeach
                    </div>
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
