@props(['width' => 'max-w-7xl'])

@php
    // The language links must return to the page the reader is on, so the
    // header works on every marketing page without being told which.
    $route = Route::currentRouteName() ?? 'home';
    $locale = app()->getLocale();
    $langQuery = \App\Http\Middleware\MarketingLocale::requested(request()) === null
        ? []
        : ['lang' => \App\Http\Middleware\MarketingLocale::requested(request())];
    $authed = auth()->check();
    $links = [
        ['label' => __('Pricing'), 'href' => route('pricing', $langQuery), 'current' => $route === 'pricing'],
    ];
@endphp

<header
    x-data="{ open: false }"
    x-on:keydown.escape.window="open = false"
    {{-- A permanent hairline rather than one that appears on scroll: the
         blur already separates the bar, and a scroll-driven border would
         depend on a scroll event this page cannot be shown to receive. --}}
    class="sticky top-0 z-50 border-b border-zinc-900/5 bg-amber-50/80 backdrop-blur-md dark:border-white/10 dark:bg-zinc-950/80"
>
    <div class="mx-auto flex w-full {{ $width }} items-center justify-between gap-3 px-6 py-4 lg:px-8">
        <div class="flex items-center gap-6">
            <a href="{{ route('home', $langQuery) }}" aria-label="{{ __('Homepage') }}" class="flex shrink-0 items-center gap-2 font-semibold">
                <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                    <x-app-logo-icon class="size-7" />
                </span>
                <span>{{ config('app.name') }}</span>
            </a>

            <nav class="hidden items-center gap-1 md:flex" aria-label="{{ __('Main') }}">
                @foreach ($links as $link)
                    <a
                        href="{{ $link['href'] }}"
                        @if ($link['current']) aria-current="page" @endif
                        @class([
                            'rounded-full px-3 py-1.5 text-sm font-medium transition',
                            'bg-white/70 text-zinc-900 dark:bg-zinc-900/70 dark:text-zinc-100' => $link['current'],
                            'text-zinc-600 hover:bg-white/70 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-900/70 dark:hover:text-zinc-100' => ! $link['current'],
                        ])
                    >{{ $link['label'] }}</a>
                @endforeach
            </nav>
        </div>

        <div class="flex items-center gap-2 sm:gap-3">
            <div class="hidden items-center gap-2 sm:gap-3 md:flex">
                <x-marketing-header.language :route="$route" :locale="$locale" />
                <x-appearance-toggle />
            </div>

            @guest
                <a href="{{ route('login') }}" class="hidden whitespace-nowrap px-2 py-1.5 text-sm font-medium text-zinc-600 hover:text-zinc-900 md:inline-flex dark:text-zinc-400 dark:hover:text-zinc-100">{{ __('Sign in') }}</a>
            @endguest

            <a
                href="{{ $authed ? url('/app') : route('register') }}"
                class="whitespace-nowrap rounded-full bg-white/80 px-3 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white sm:px-4 dark:bg-zinc-900/80 dark:text-zinc-200 dark:ring-zinc-800 dark:hover:bg-zinc-900"
            >{{ $authed ? __('Open app') : __('Create account') }}</a>

            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open ? 'true' : 'false'"
                aria-controls="marketing-menu"
                class="relative -mr-1 flex size-9 items-center justify-center rounded-full text-zinc-600 hover:bg-white/70 hover:text-zinc-900 md:hidden dark:text-zinc-400 dark:hover:bg-zinc-900/70 dark:hover:text-zinc-100"
            >
                <span class="sr-only" x-text="open ? @js(__('Close menu')) : @js(__('Menu'))">{{ __('Menu') }}</span>
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" class="size-5" aria-hidden="true">
                    <path x-bind:class="open ? 'hidden' : ''" d="M3 6h14M3 10h14M3 14h14" />
                    <path x-bind:class="open ? '' : 'hidden'" class="hidden" d="M5 5l10 10M15 5L5 15" />
                </svg>
                {{-- Keeps the touch target at 48px without growing the bar. --}}
                <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
            </button>
        </div>
    </div>

    <div
        id="marketing-menu"
        x-show="open"
        x-cloak
        x-collapse
        class="md:hidden"
    >
        <div class="mx-auto flex w-full {{ $width }} flex-col gap-1 px-6 py-4 lg:px-8">
            @foreach ($links as $link)
                <a href="{{ $link['href'] }}" @if ($link['current']) aria-current="page" @endif class="rounded-xl px-3 py-2.5 text-base font-medium text-zinc-700 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-zinc-900/70">{{ $link['label'] }}</a>
            @endforeach

            @guest
                <a href="{{ route('login') }}" class="rounded-xl px-3 py-2.5 text-base font-medium text-zinc-700 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-zinc-900/70">{{ __('Sign in') }}</a>
            @endguest

            <div class="mt-3 flex items-center justify-between border-t border-zinc-200/70 pt-4 dark:border-zinc-800/70">
                <x-marketing-header.language :route="$route" :locale="$locale" />
                <x-appearance-toggle />
            </div>
        </div>
    </div>
</header>
