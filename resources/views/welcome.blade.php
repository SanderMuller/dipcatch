@php
    $h1 = __('Catch every price drop.');
    $sub = __('Track products across the web. DipCatch watches prices around the clock and pings you the moment they fall past your threshold.');
    $authed = auth()->check();
    $primaryHref = $authed ? url('/app') : route('login');
    $primaryLabel = $authed ? __('Open dashboard') : __('Sign in');
    $headerLabel = $authed ? __('Open app') : __('Sign in');
    $steps = [
        ['n' => '01', 'title' => __('Paste a URL'), 'body' => __('Drop in a product link and a CSS selector for the price. DipCatch grabs the title and image too.')],
        ['n' => '02', 'title' => __('We watch the price'), 'body' => __('Scheduled scrapes record every price point. No headless browser — fast, lightweight checks.')],
        ['n' => '03', 'title' => __('You get the dip'), 'body' => __('When the price falls past your threshold, you get an email, in-app bell, and web push.')],
    ];
    $tracked = [
        ['icon' => '🎧', 'name' => 'Sony WH-1000XM5', 'shop' => 'amazon.com', 'old' => 399, 'new' => 249, 'pct' => '-37.6%', 'when' => 'now'],
        ['icon' => '📚', 'name' => 'Kindle Paperwhite', 'shop' => 'bol.com', 'old' => 159, 'new' => 119, 'pct' => '-25.2%', 'when' => '2h'],
        ['icon' => '🧹', 'name' => 'Dyson V15 Detect', 'shop' => 'mediamarkt.nl', 'old' => 749, 'new' => 549, 'pct' => '-26.7%', 'when' => 'yesterday'],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth bg-amber-50 dark:bg-zinc-950">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-linear-to-br from-amber-50 to-rose-50 bg-fixed text-zinc-900 antialiased dark:from-zinc-950 dark:to-zinc-950 dark:text-zinc-50">
        <div class="isolate flex min-h-dvh flex-col">
            <div class="relative flex flex-1 flex-col overflow-hidden">
                <div aria-hidden="true" class="pointer-events-none absolute -top-40 -left-40 size-[28rem] rounded-full bg-amber-200/40 blur-3xl dark:hidden"></div>
                <div aria-hidden="true" class="pointer-events-none absolute right-0 -bottom-32 size-[28rem] rounded-full bg-rose-200/40 blur-3xl dark:hidden"></div>

                <header class="relative mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-6 lg:px-8">
                    <a href="{{ route('home') }}" aria-label="{{ __('Homepage') }}" class="flex items-center gap-2 font-semibold">
                        <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                            <x-app-logo-icon class="size-7" />
                        </span>
                        <span>{{ config('app.name') }}</span>
                    </a>
                    <nav class="flex items-center gap-3">
                        <x-appearance-toggle />
                        <a href="{{ $primaryHref }}" class="rounded-full bg-white/80 px-4 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white dark:bg-zinc-900/80 dark:text-zinc-200 dark:ring-zinc-800 dark:hover:bg-zinc-900">{{ $headerLabel }}</a>
                    </nav>
                </header>

                <main class="relative mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <section class="grid items-center gap-12 py-16 sm:py-24 lg:grid-cols-12 lg:gap-12">
                        <div class="lg:col-span-7">
                            <span class="inline-flex items-center gap-2 rounded-full bg-white/80 px-3 py-1 text-xs font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/60 dark:text-zinc-300 dark:ring-zinc-800">
                                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                {{ __('Invite-only beta') }}
                            </span>
                            <h1 class="mt-6 max-w-[24ch] text-4xl font-semibold tracking-tight text-balance sm:text-6xl">{{ $h1 }}</h1>
                            <p class="mt-6 max-w-[48ch] text-lg text-pretty text-zinc-600 sm:text-xl dark:text-zinc-400">{{ $sub }}</p>
                            @auth
                                <div class="mt-10 flex flex-wrap items-center gap-3">
                                    <a href="{{ url('/app') }}" class="inline-flex items-center rounded-full bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white shadow-md hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:bg-white dark:text-zinc-900 dark:shadow-none dark:hover:bg-zinc-200">{{ __('Open dashboard') }} <span aria-hidden="true" class="ml-1">&rarr;</span></a>
                                </div>
                            @else
                                <div class="mt-10">
                                    <livewire:waitlist-signup />
                                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('Already invited?') }}
                                        <a href="{{ route('login') }}" class="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-700 dark:text-zinc-100 dark:hover:text-zinc-300">{{ __('Sign in') }}</a>
                                    </p>
                                </div>
                            @endauth
                        </div>

                        <div class="lg:col-span-5">
                            <div class="relative mx-auto w-72 rotate-3">
                                <div class="rounded-[2.5rem] bg-zinc-900 p-3 shadow-2xl ring-1 ring-zinc-800 dark:shadow-none">
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
                                                            <div class="flex items-baseline justify-between gap-2">
                                                                <p class="truncate text-xs font-semibold">{{ __('DipCatch') }}</p>
                                                                <p class="text-[0.625rem] text-zinc-500 dark:text-zinc-400">{{ $p['when'] }}</p>
                                                            </div>
                                                            <p class="mt-0.5 truncate text-xs">{{ $p['name'] }}</p>
                                                            <p class="text-xs text-emerald-600 tabular-nums dark:text-emerald-400">{{ __('Dropped to €:new · :pct', ['new' => $p['new'], 'pct' => $p['pct']]) }}</p>
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
                        <p class="mt-4 max-w-[56ch] text-base text-pretty text-zinc-600 dark:text-zinc-400">{{ __('Add a product, set a threshold, get notified. No browser extensions, no manual checking.') }}</p>
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
                </main>

                <footer class="relative mx-auto mt-auto w-full max-w-7xl px-6 pb-10 lg:px-8">
                    <div class="flex flex-col items-center justify-between gap-3 border-t border-zinc-200 pt-6 text-sm text-zinc-500 sm:flex-row dark:border-zinc-800 dark:text-zinc-400">
                        <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
                    </div>
                </footer>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
