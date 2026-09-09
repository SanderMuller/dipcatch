<!DOCTYPE html>
{{-- No hardcoded `dark` class: @fluxAppearance decides the mode before
     first paint from localStorage or the OS. Hardcoding it flashed a dark
     page at everyone whose system is light. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
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
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-900/5 bg-amber-50/80 backdrop-blur-md dark:border-white/10 dark:bg-zinc-950/80">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" :href="route('app.dashboard')" wire:navigate />
                {{-- Bell slot, desktop. --}}
                @includeWhen(view()->exists('partials.notification-bell'), 'partials.notification-bell')
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{-- Every navigation entry is declared here, in one place. --}}
            <flux:sidebar.nav>
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="home" :href="route('app.dashboard')" :current="request()->routeIs('app.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="shopping-bag" :href="route('app.products.index')" :current="request()->routeIs('app.products.*')" wire:navigate>
                        {{ __('Products') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="credit-card" :href="route('app.billing')" :current="request()->routeIs('app.billing')" wire:navigate>
                        {{ __('Plan & billing') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="bell" :href="route('app.notifications')" :current="request()->routeIs('app.notifications')" wire:navigate>
                        {{ __('Notifications') }}
                    </flux:sidebar.item>

                    <flux:sidebar.item icon="puzzle-piece" :href="route('app.connections')" :current="request()->routeIs('app.connections')" wire:navigate>
                        {{ __('Connections') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="border-b border-zinc-900/5 bg-amber-50/80 backdrop-blur-md lg:hidden dark:border-white/10 dark:bg-zinc-950/80">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            {{-- Bell slot, mobile. --}}
            @includeWhen(view()->exists('partials.notification-bell'), 'partials.notification-bell')

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>

                        {{-- Only an admin can open /admin at all (User::canAccessPanel), so this is a shortcut for people who already hold the key, never the thing that grants it. --}}
                        @if (auth()->user()->is_admin)
                            <flux:menu.item href="/admin" icon="wrench-screwdriver">
                                {{ __('Admin panel') }}
                            </flux:menu.item>
                        @endif
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

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

        @fluxScripts
    </body>
</html>
