<!DOCTYPE html>
{{-- No hardcoded `dark` class: the appearance script in partials.head decides
     the mode before first paint, defaulting to light. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')

        {{-- Filament's notification toasts. The admin panel boots these for
             itself; this layout is Flux and had no outlet, so every
             `Notification::make()->send()` from a Livewire component here was
             built, queued and never drawn — a shop kept as a link saved
             correctly and said nothing. Found by driving it in a browser on
             2026-09-23. --}}
        @filamentStyles
        <link rel="stylesheet" href="{{ asset('css/filament/filament/app.css') }}">
    </head>
    {{-- The same warm canvas as the marketing site, so the app a person
         lands in after signing up looks like the page that sold it. --}}
    <body class="min-h-dvh bg-amber-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-50">
        {{-- The same two blurred washes the marketing hero uses. They are what
             gives that page its depth, and a flat tint could not reproduce it.
             Fixed and behind everything, so scrolling and hit-testing are
             untouched; light mode only, as on the marketing page. --}}
        <div aria-hidden="true" class="pointer-events-none fixed -top-40 left-40 -z-10 size-[32rem] rounded-full bg-amber-200/40 blur-3xl dark:hidden"></div>
        <div aria-hidden="true" class="pointer-events-none fixed right-0 -bottom-40 -z-10 size-[32rem] rounded-full bg-rose-200/40 blur-3xl dark:hidden"></div>

        @php
            // One declaration of the navigation, read by the bar, the "More"
            // dropdown and the mobile sheet, so the three cannot drift.
            // No Dashboard entry: the logo goes there, as on a shop header.
            // Stats is its own page and its own entry below, so it does not
            // fold in here — the logo marked itself as the page being read
            // while pointing somewhere else.
            $dashboardIsCurrent = request()->routeIs('app.dashboard');

            $primaryLinks = [
                // Current on the index only. On a product page the pill read
                // as "you are here", so the way back to the list looked dead.
                ['label' => __('Products'), 'href' => route('app.products.index'), 'icon' => 'shopping-bag', 'current' => request()->routeIs('app.products.index'), 'navigate' => true],
            ];

            $moreLinks = [
                // "Notifications" read as a list of alerts you have had. The
                // page is the settings for them.
                ['label' => __('Notification settings'), 'href' => route('app.notifications'), 'icon' => 'bell', 'current' => request()->routeIs('app.notifications'), 'navigate' => true],
                // Reachable from the savings tile on the dashboard too, but a
                // page with one subtle link into it and none out is a page a
                // reader cannot find twice.
                ['label' => __('Stats'), 'href' => route('app.stats'), 'icon' => 'chart-bar', 'current' => request()->routeIs('app.stats'), 'navigate' => true],
                ['label' => __('Plan & billing'), 'href' => route('app.billing'), 'icon' => 'credit-card', 'current' => request()->routeIs('app.billing'), 'navigate' => true],
                ['label' => __('Connections'), 'href' => route('app.connections'), 'icon' => 'puzzle-piece', 'current' => request()->routeIs('app.connections'), 'navigate' => true],
                ['label' => __('Support'), 'href' => route('app.support'), 'icon' => 'lifebuoy', 'current' => request()->routeIs('app.support'), 'navigate' => true],
                // Back to the marketing site. No wire:navigate: the marketing
                // pages use their own layout, so a full page load is correct.
                ['label' => __('Home page'), 'href' => route('home'), 'icon' => 'globe-alt', 'current' => false, 'navigate' => false, 'test' => 'home-page-nav'],
            ];

            $moreIsCurrent = collect($moreLinks)->contains('current', true);
        @endphp

        <header
            x-data="{ open: false }"
            x-on:keydown.escape.window="open = false"
            class="sticky top-0 z-50 border-b border-zinc-900/5 bg-amber-50/80 backdrop-blur-md dark:border-white/10 dark:bg-zinc-950/80"
        >
            <div class="mx-auto flex w-full max-w-app items-center gap-3 px-4 py-3 sm:px-6 lg:gap-6 lg:px-8">
                <a href="{{ route('app.dashboard') }}" wire:navigate aria-label="{{ __('Dashboard') }}" @if ($dashboardIsCurrent) aria-current="page" @endif class="flex shrink-0 items-center gap-2 font-semibold">
                    <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5 dark:bg-white">
                        <x-app-logo-icon class="size-7" />
                    </span>
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>

                <nav class="hidden items-center gap-1 lg:flex" aria-label="{{ __('Main') }}">
                    @foreach ($primaryLinks as $link)
                        <a
                            href="{{ $link['href'] }}"
                            @if ($link['navigate']) wire:navigate @endif
                            @if ($link['current']) aria-current="page" @endif
                            {{-- The current page is a raised white pill: a ring and a small
                                 shadow lift it off the amber canvas, where a translucent
                                 fill alone barely read. Hover is a soft tint instead, so
                                 the two states differ in kind, not only in amount. --}}
                            @class([
                                'rounded-full px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                                'bg-white text-zinc-900 ring-1 shadow-sm ring-zinc-950/10 dark:bg-zinc-800 dark:text-white dark:shadow-none dark:ring-white/10' => $link['current'],
                                'text-zinc-600 hover:bg-zinc-950/5 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100' => ! $link['current'],
                            ])
                        >{{ $link['label'] }}</a>
                    @endforeach

                    <flux:dropdown position="bottom" align="start">
                        <button
                            type="button"
                            @class([
                                'flex items-center gap-1 rounded-full px-3 py-1.5 text-sm font-medium whitespace-nowrap',
                                'bg-white text-zinc-900 ring-1 shadow-sm ring-zinc-950/10 dark:bg-zinc-800 dark:text-white dark:shadow-none dark:ring-white/10' => $moreIsCurrent,
                                'text-zinc-600 hover:bg-zinc-950/5 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100' => ! $moreIsCurrent,
                            ])
                            data-test="more-menu-button"
                        >
                            {{ __('More') }}
                            <flux:icon.chevron-down class="size-4" />
                        </button>

                        <flux:menu>
                            @foreach ($moreLinks as $link)
                                <flux:menu.item
                                    :href="$link['href']"
                                    :icon="$link['icon']"
                                    :wire:navigate="$link['navigate']"
                                    :data-test="$link['test'] ?? null"
                                >
                                    {{ $link['label'] }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </nav>

                {{-- The search bar is the widest thing in the bar, as on a
                     shop header. It opens the command palette modal. --}}
                <livewire:app-command-palette />

                <div class="flex shrink-0 items-center gap-1 sm:gap-2">
                    {{-- Bell slot. --}}
                    @includeWhen(view()->exists('partials.notification-bell'), 'partials.notification-bell')

                    <x-appearance-toggle class="hidden sm:flex" />

                    <x-desktop-user-menu class="hidden lg:block" />

                    <button
                        type="button"
                        x-on:click="open = ! open"
                        x-bind:aria-expanded="open ? 'true' : 'false'"
                        aria-controls="app-menu"
                        class="flex size-9 items-center justify-center rounded-full text-zinc-600 hover:bg-zinc-950/5 hover:text-zinc-900 lg:hidden dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                    >
                        <span class="sr-only" x-text="open ? @js(__('Close menu')) : @js(__('Menu'))">{{ __('Menu') }}</span>
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" class="size-5" aria-hidden="true">
                            <path x-bind:class="open ? 'hidden' : ''" d="M3 6h14M3 10h14M3 14h14" />
                            <path x-bind:class="open ? '' : 'hidden'" class="hidden" d="M5 5l10 10M15 5L5 15" />
                        </svg>
                    </button>
                </div>
            </div>

            <div id="app-menu" x-show="open" x-cloak x-collapse class="lg:hidden">
                <div class="mx-auto flex w-full max-w-app flex-col gap-1 px-4 py-4 sm:px-6 lg:px-8">
                    @foreach (array_merge($primaryLinks, $moreLinks) as $link)
                        <a
                            href="{{ $link['href'] }}"
                            @if ($link['navigate']) wire:navigate @endif
                            @if ($link['current']) aria-current="page" @endif
                            @isset($link['test']) data-test="{{ $link['test'] }}" @endisset
                            @class([
                                'rounded-xl px-3 py-2.5 text-base font-medium',
                                'bg-white text-zinc-900 ring-1 shadow-sm ring-zinc-950/10 dark:bg-zinc-800 dark:text-white dark:shadow-none dark:ring-white/10' => $link['current'],
                                'text-zinc-700 hover:bg-zinc-950/5 dark:text-zinc-300 dark:hover:bg-white/10' => ! $link['current'],
                            ])
                        >{{ $link['label'] }}</a>
                    @endforeach

                    <div class="mt-3 flex items-center justify-between border-t border-zinc-200/70 pt-4 dark:border-zinc-800/70">
                        <x-desktop-user-menu />
                        <x-appearance-toggle class="sm:hidden" />
                    </div>
                </div>
            </div>
        </header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{--
            Timezone auto-detection runs on every authenticated page, not only
            settings. It fired panel-wide under Filament; a user who never opens
            settings would otherwise keep a wrong digest hour. The view
            short-circuits when timezone_detected_at is already set.
        --}}
        @include('partials.timezone-autodetect')

        {{-- The outlet itself, and the script that animates it. Last in the
             body so a toast paints above the page rather than inside the
             layout's stacking contexts. --}}
        @livewire('notifications')

        @filamentScripts
        @fluxScripts
    </body>
</html>
