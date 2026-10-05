<div>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="text-2xl! font-semibold! tracking-tight sm:text-3xl!">{{ __('Dashboard') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Where to shop this week, what dropped, and what needs you.') }}
            </flux:text>
        </div>

        <a href="{{ route('app.products.index') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-paper py-1 pr-2 pl-3 text-sm font-medium ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            {{ __('All products') }}
            <flux:icon.arrow-right variant="micro" class="size-4 shrink-0" />
        </a>
    </div>

    {{-- The counts are context, not the point of the page, so they sit on
         one quiet line rather than a card of their own. --}}
    <dl class="mt-4 flex flex-wrap items-baseline gap-x-6 gap-y-2 text-sm">
        <div class="flex items-baseline gap-1.5">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Tracked products') }}</dt>
            <dd class="font-semibold tabular-nums">{{ $trackedProducts }}</dd>
        </div>
        <div class="flex items-baseline gap-1.5">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Active drops') }}</dt>
            <dd @class(['font-semibold tabular-nums', 'text-savings-strong' => $activeDropCount > 0])>{{ $activeDropCount }}</dd>
        </div>
        <div class="flex items-baseline gap-1.5">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Potential savings so far') }}</dt>
            <dd class="font-semibold tabular-nums">{{ $lifetimeSavings }}</dd>
            <dd>
                <flux:link :href="route('app.stats')" variant="subtle" class="text-sm" wire:navigate data-test="savings-by-month-link">
                    {{ __('See it by month') }}
                </flux:link>
            </dd>
        </div>
    </dl>

    @unless ($hasAnyProduct)
        <flux:callout class="mt-6" icon="sparkles">
            <flux:callout.heading>{{ __('Track your first product') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Paste a product link and DipCatch watches it at every shop that sells it.') }}</flux:callout.text>
            @if ($canAddProduct)
                <flux:button class="mt-3 rounded-full!" :href="route('app.products.create')" variant="primary" wire:navigate>
                    {{ __('Track a product') }}
                </flux:button>
            @endif
        </flux:callout>
    @endunless

    <livewire:dashboard.tracking-ideas />

    <div class="mt-8 grid items-start gap-x-8 gap-y-10 lg:grid-cols-[minmax(0,1fr)_22rem] xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="min-w-0 space-y-10">
            @if ($digest->trips !== [])
                <section data-test="shopping-trips">
                    <flux:heading size="xl" level="2" class="font-semibold! tracking-tight">{{ __('Where to shop this week') }}</flux:heading>
                    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Shops where several of your products are cheapest, and at least one is on offer.') }}</flux:text>

                    @foreach ([
                        ['trips' => $storeTrips, 'label' => __('Shops with a store'), 'note' => __('Go there, or order online where the shop delivers.'), 'icon' => 'building-storefront'],
                        ['trips' => $onlineTrips, 'label' => __('Online only'), 'note' => null, 'icon' => 'truck'],
                    ] as $group)
                        @if ($group['trips'] !== [])
                            <div class="mt-5">
                                <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <h3 class="inline-flex items-center gap-1.5 self-center font-semibold tracking-wide uppercase">
                                        <flux:icon :name="$group['icon']" variant="micro" class="size-4" />
                                        {{ $group['label'] }}
                                    </h3>
                                    @if ($group['note'] !== null)
                                        <p>{{ $group['note'] }}</p>
                                    @endif
                                </div>
                                {{-- Laid out by the column's width: from lg up the sidebar narrows it. --}}
                                <ul role="list" class="@container mt-2 divide-y divide-ink/5 rounded-2xl bg-paper shadow-xs ring-1 ring-line dark:divide-white/10">
                                    @foreach ($group['trips'] as $trip)
                                        <li class="flex flex-col gap-3 px-4 py-3.5 @3xl:flex-row @3xl:items-center @3xl:gap-5" wire:key="trip-{{ $trip['host'] }}">
                                            <div class="min-w-0 @3xl:w-44 @3xl:shrink-0">
                                                <a href="{{ route('app.products.index', ['shop' => $trip['host'], 'bestBuy' => 'true']) }}" wire:navigate class="inline-flex max-w-full min-w-0 items-center font-semibold underline-offset-4 hover:underline">{!! \App\Support\Favicon::html($trip['host']) !!}</a>
                                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count best buy|:count best buys', $trip['count'], ['count' => $trip['count']]) }}</p>
                                            </div>
                                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                                <div class="flex shrink-0 -space-x-3">
                                                    @foreach ($trip['products'] as $product)
                                                        <a href="{{ route('app.products.show', $product) }}" wire:navigate title="{{ $product->title }}" class="rounded-xl ring-2 ring-paper">
                                                            <x-product-thumb :product="$product" size="size-10" />
                                                            <span class="sr-only">{{ $product->title }}</span>
                                                        </a>
                                                    @endforeach
                                                </div>
                                                @if ($trip['count'] > count($trip['products']))
                                                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('and :count more', ['count' => $trip['count'] - count($trip['products'])]) }}</span>
                                                @endif
                                            </div>
                                            <div class="flex shrink-0 items-center gap-3">
                                                @if ($trip['onOffer'] > 0)
                                                    {{-- Best buys and discounted: the same products the badge counts, not every deal at the shop. --}}
                                                    <a href="{{ route('app.products.index', ['shop' => $trip['host'], 'bestBuy' => 'true', 'discounted' => 'true']) }}" wire:navigate class="rounded-full bg-savings/10 px-2 py-0.5 text-xs font-semibold text-savings-strong hover:bg-savings/20">{{ trans_choice(':count on offer|:count on offer', $trip['onOffer'], ['count' => $trip['onOffer']]) }}</a>
                                                @endif
                                                <a href="{{ route('app.products.index', ['shop' => $trip['host']]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-brand">{{ __('Everything at :shop', ['shop' => $trip['host']]) }} <flux:icon.arrow-right variant="micro" class="size-4" /></a>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </section>
            @endif

            @if ($atAlert->isNotEmpty())
                <section data-test="at-alert">
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('At your alert price') }}</flux:heading>
                    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('At or under the price you set an alert for.') }}</flux:text>

                    <div class="@container mt-4">
                        <x-product-card.grid :columns="0" class="@3xl:grid-cols-3">
                            @foreach ($atAlert as $product)
                                <li class="min-w-0" wire:key="alert-{{ $product->id }}">
                                    <x-product-card :product="$product" :compare="false" />
                                </li>
                            @endforeach
                        </x-product-card.grid>
                    </div>
                </section>
            @endif

            {{-- A product at its alert price shows above, not here as well.
                 Hidden when the alert section shows and no drop is left to add. --}}
            @if ($dropCards->isNotEmpty() || $atAlert->isEmpty())
                <section>
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Biggest drops') }}</flux:heading>
                            <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Cheaper than their normal price, biggest drop first.') }}</flux:text>
                        </div>
                        @if ($activeDropCount > 0)
                            <flux:link :href="route('app.products.index', ['discounted' => 'true'])" variant="subtle" class="shrink-0 text-sm" wire:navigate>{{ __('All drops') }}</flux:link>
                        @endif
                    </div>

                    @if ($dropCards->isEmpty())
                        <flux:text size="sm" class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('No active drops right now. You hear from DipCatch as soon as a price drops far enough.') }}</flux:text>
                    @else
                        {{-- Sized by the column, not the window: from lg up a
                             fixed-width sidebar sits beside it. --}}
                        <div class="@container mt-4">
                            <x-product-card.grid :columns="0" class="@3xl:grid-cols-3">
                                @foreach ($dropCards as $product)
                                    <li class="min-w-0" wire:key="drop-{{ $product->id }}">
                                        <x-product-card :product="$product" :compare="false">
                                            @if ($product->last_notified_at)
                                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                                    {{ __('Dropped :ago', ['ago' => $product->last_notified_at->diffForHumans()]) }}
                                                </flux:text>
                                            @endif
                                        </x-product-card>
                                    </li>
                                @endforeach
                            </x-product-card.grid>
                        </div>
                    @endif
                </section>
            @endif

            @if ($watching->isNotEmpty())
                <section>
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Recently added') }}</flux:heading>
                    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('The last products you added.') }}</flux:text>

                    <ul role="list" class="mt-4 divide-y divide-ink/5 rounded-2xl bg-paper ring-1 ring-line dark:divide-white/10">
                        @foreach ($watching as $product)
                            <li class="relative flex items-center gap-3 px-4 py-2.5 hover:bg-canvas/60 dark:hover:bg-white/5" wire:key="watching-{{ $product->id }}">
                                <x-product-thumb :product="$product" size="size-10" @class(['opacity-40 grayscale' => ! $product->active]) />
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('app.products.show', $product) }}" wire:navigate class="block truncate font-medium after:absolute after:inset-0">{{ $product->title }}</a>
                                    <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ implode(' · ', array_filter([
                                            __('Added :ago', ['ago' => $product->created_at?->diffForHumans()]),
                                            trans_choice(':count shop|:count shops', $product->shops_count, ['count' => $product->shops_count]),
                                            $product->active ? null : __('Paused'),
                                        ])) }}
                                    </p>
                                </div>
                                <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="min-w-0 space-y-8">
            @if ($needsSecondShop)
                <flux:callout icon="scale">
                    <flux:callout.heading>{{ __('Add a second shop to compare') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('One shop gives you a price history. A second one tells you which shop is cheaper, per kilo, litre or piece.') }}
                    </flux:callout.text>
                    {{-- Lands on the product with the add-shop form already open, so the
                         button does what its label says in one step. --}}
                    <flux:button class="mt-3" size="sm" :href="route('app.products.show', [$watching->first(), 'add-shop' => 1])" variant="primary" wire:navigate data-test="add-second-shop">
                        {{ __('Add a shop') }}
                    </flux:button>
                </flux:callout>
            @endif

            @if ($hasAnyProduct)
                <livewire:dashboard-suggested-shops />
            @endif

            @if ($digest->endingSoon !== [] || $digest->failing !== [] || $digest->singleShop !== [])
                <section class="min-w-0" data-test="worth-a-look">
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Worth a look') }}</flux:heading>
                    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Deals about to stop, and products DipCatch cannot follow well.') }}</flux:text>

                    @php($worthALook = [
                        ...array_map(fn (array $row): array => ['key' => 'ending-' . $row['shop']->id, 'product' => $row['product'], 'icon' => 'clock', 'tone' => 'text-chart-line', 'note' => __('Deal at :shop ends :when', ['shop' => $row['shop']->host, 'when' => $row['endsAt']->diffForHumans()]), 'addShop' => false], $digest->endingSoon),
                        ...array_map(fn (array $row): array => ['key' => 'failing-' . $row['shop']->id, 'product' => $row['product'], 'icon' => 'exclamation-triangle', 'tone' => 'text-alert', 'note' => __('DipCatch cannot read :shop right now. The page may have moved.', ['shop' => $row['shop']->host]), 'addShop' => false], $digest->failing),
                        ...array_map(fn ($product): array => ['key' => 'single-' . $product->id, 'product' => $product, 'icon' => 'scale', 'tone' => 'text-zinc-500 dark:text-zinc-400', 'note' => __('Tracked at one shop only.'), 'addShop' => true], $digest->singleShop),
                    ])
                    <ul role="list" class="mt-4 divide-y divide-ink/5 rounded-2xl bg-paper ring-1 ring-line dark:divide-white/10">
                        @foreach ($worthALook as $row)
                            <li class="relative flex items-center gap-3 px-4 py-2.5 hover:bg-canvas/60 dark:hover:bg-white/5" wire:key="list-{{ $row['key'] }}">
                                <div class="relative shrink-0">
                                    <x-product-thumb :product="$row['product']" size="size-10" />
                                    <span class="absolute -right-1.5 -bottom-1.5 flex size-5 items-center justify-center rounded-full bg-paper shadow-xs ring-1 ring-line dark:bg-zinc-900 dark:ring-white/10" aria-hidden="true">
                                        <flux:icon :name="$row['icon']" variant="micro" class="size-3.5 {{ $row['tone'] }}" />
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('app.products.show', $row['product']) }}" wire:navigate class="block truncate font-medium after:absolute after:inset-0">{{ $row['product']->title }}</a>
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $row['note'] }}
                                        @if ($row['addShop'])
                                            {{-- Above the link stretched over the row. --}}
                                            <a href="{{ route('app.products.show', [$row['product'], 'add-shop' => 1]) }}" wire:navigate class="relative z-10 font-medium text-brand underline underline-offset-4">{{ __('Add a shop') }}</a>
                                        @endif
                                    </p>
                                </div>
                                <flux:icon.chevron-right variant="micro" class="size-4 shrink-0 text-zinc-400" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </aside>
    </div>
</div>
