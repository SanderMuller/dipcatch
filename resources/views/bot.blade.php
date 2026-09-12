@php
    // English only and outside MarketingLocale: the readers are shop
    // operators deciding whether to allow the crawler, not customers.
    $userAgent = config('scraper.user_agent');
    $intervalHours = config('dipcatch.recheck.interval_hours', 6);
    $contactEmail = config('site.contact_email');
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head', [
            'title' => 'DipCatchBot',
            'description' => 'What the DipCatch crawler fetches, how often, and how to block it.',
            'canonical' => route('bot'),
        ])
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="flex min-h-dvh flex-col">
            <header class="mx-auto w-full max-w-3xl px-6 pt-8 lg:px-8">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 font-semibold">
                    <x-app-logo-icon class="size-6 fill-current" />
                    {{ config('app.name') }}
                </a>
            </header>

            <main class="mx-auto w-full max-w-3xl flex-1 px-6 pt-8 pb-20 lg:px-8">
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">DipCatchBot</h1>
                <p class="mt-2 max-w-2xl text-base text-zinc-600 dark:text-zinc-300">
                    DipCatchBot is the crawler behind {{ config('app.name') }}, a price-alert service for shoppers in
                    the Netherlands. This page tells you what it does and how to stop it.
                </p>

                <div class="mt-10 space-y-8 text-base text-zinc-700 dark:text-zinc-300">
                    <section>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">What it fetches</h2>
                        <p class="mt-2">
                            Only product pages that one of our users has added by pasting the link. It reads the title,
                            image, price and pack size, and it stores nothing else from the page. It does not crawl your
                            site looking for pages, and it does not follow links.
                        </p>
                    </section>

                    <section>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">How often</h2>
                        <p class="mt-2">
                            Once when a user adds the link, then about every {{ $intervalHours }} hours for as long as
                            they track that product. One request per tracked product, spread out with jitter, and rate
                            limited per host.
                        </p>
                    </section>

                    <section>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">How to recognise it</h2>
                        <p class="mt-2">It sends this user agent:</p>
                        <pre class="mt-3 overflow-x-auto rounded-xl bg-white/70 p-4 text-sm ring-1 ring-zinc-200 dark:bg-zinc-900/70 dark:ring-zinc-800"><code>{{ $userAgent }}</code></pre>
                    </section>

                    <section>
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">How to block it</h2>
                        <p class="mt-2">
                            DipCatchBot reads your <code>robots.txt</code> before every request and honours it. Add this
                            and it will stop:
                        </p>
                        <pre class="mt-3 overflow-x-auto rounded-xl bg-white/70 p-4 text-sm ring-1 ring-zinc-200 dark:bg-zinc-900/70 dark:ring-zinc-800"><code>User-agent: DipCatchBot
Disallow: /</code></pre>
                        <p class="mt-3">
                            Users tracking your products will then see the shop stop working, and we will not retry
                            around the block.
                        </p>
                    </section>

                    @if (filled($contactEmail))
                        <section>
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-zinc-50">Questions</h2>
                            <p class="mt-2">
                                Mail <a href="mailto:{{ $contactEmail }}" class="font-medium underline underline-offset-4">{{ $contactEmail }}</a>
                                if you want a different crawl rate, or if something here does not match what you see in your logs.
                            </p>
                        </section>
                    @endif
                </div>
            </main>
        </div>
    </body>
</html>
