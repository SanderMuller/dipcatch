<x-filament-widgets::widget>
    <x-filament::section>
        <div class="grid gap-10 lg:grid-cols-12 lg:items-start">
            <div class="lg:col-span-7">
                <p class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">Welcome to DipCatch</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white sm:text-3xl">Track your first product</h2>
                <p class="mt-3 max-w-prose text-sm text-gray-600 dark:text-gray-400 sm:text-base">
                    Paste a link to something you buy anyway — protein bars, cheese, toilet paper. DipCatch watches the price across shops and tells you when it drops.
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <x-filament::button tag="a" :href="$createUrl" size="lg" icon="heroicon-m-plus">
                        Track your first product
                    </x-filament::button>
                    <x-filament::link :href="$settingsUrl" color="gray" icon="heroicon-m-bell">
                        Alert settings
                    </x-filament::link>
                </div>

                <div class="mt-10">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Works with</p>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($shops as $shop)
                            <li class="inline-flex items-center gap-2 rounded-full bg-gray-50 px-3 py-1.5 text-sm text-gray-700 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10">
                                <img src="{{ $shop['favicon'] }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                                {{ $shop['host'] }}
                            </li>
                        @endforeach
                        <li class="inline-flex items-center rounded-full px-3 py-1.5 text-sm text-gray-500 dark:text-gray-400">+ most webshops that show a price</li>
                    </ul>
                </div>
            </div>

            <ol class="grid gap-4 lg:col-span-5">
                @foreach ([
                    ['Paste a product link', 'We read the title, image, price and pack size, and suggest a drop threshold.', 'heroicon-o-link'],
                    ['Add the same item from other shops', 'The cheapest offer wins. Unit prices (€/kg, €/l) make different pack sizes comparable.', 'heroicon-o-scale'],
                    ['Get told when it drops', 'Prices are re-checked automatically. Alerts land in your daily digest, the bell, or browser push.', 'heroicon-o-arrow-trending-down'],
                ] as $index => [$title, $description, $icon])
                    <li class="flex gap-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-primary-600 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-primary-400 dark:ring-white/10">
                            <x-filament::icon :icon="$icon" class="size-5" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                                <span class="text-gray-400 dark:text-gray-500">{{ $index + 1 }}.</span> {{ $title }}
                            </p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $description }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
