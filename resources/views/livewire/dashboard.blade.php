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

    @if ($needsSecondShop)
        <flux:callout class="mt-6" icon="scale">
            <flux:callout.heading>{{ __('Add a second shop to compare') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('One shop gives you a price history. A second one tells you which shop is cheaper, per kilo, litre or piece.') }}
            </flux:callout.text>
            {{-- Lands on the product with the add-shop form already open, so the
                 button does what its label says in one step. --}}
            <flux:button class="mt-3" :href="route('app.products.show', [$watching->first(), 'add-shop' => 1])" variant="primary" wire:navigate data-test="add-second-shop">
                {{ __('Add a shop') }}
            </flux:button>
        </flux:callout>
    @endif

    @if ($digest->trips !== [])
        <section class="mt-8" data-test="shopping-trips">
            <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Where to shop this week') }}</flux:heading>
            <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Your products, under the shop where each is the best buy right now.') }}</flux:text>

            <flux:card class="mt-4 p-0!">
                <ul role="list" class="divide-y divide-ink/5">
                    @foreach ($digest->trips as $trip)
                        <li class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:gap-6" wire:key="trip-{{ $trip['host'] }}">
                            <div class="w-40 shrink-0">
                                <p class="font-semibold"><x-shop-link :shop="$trip['shop']" /></p>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count best buy|:count best buys', $trip['count'], ['count' => $trip['count']]) }}</p>
                            </div>
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <div class="flex shrink-0 -space-x-3">
                                    @foreach ($trip['products'] as $product)
                                        <a href="{{ route('app.products.show', $product) }}" wire:navigate title="{{ $product->title }}" class="rounded-xl ring-2 ring-paper">
                                            <x-product-thumb :product="$product" size="size-11" />
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
                                    <span class="rounded-full bg-savings/10 px-2 py-0.5 text-xs font-semibold text-savings-strong">{{ trans_choice(':count on offer|:count on offer', $trip['onOffer'], ['count' => $trip['onOffer']]) }}</span>
                                @endif
                                <a href="{{ route('app.products.index', ['shop' => $trip['host']]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-brand">{{ __('Everything at :shop', ['shop' => $trip['host']]) }} <flux:icon.arrow-right variant="micro" class="size-4" /></a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        </section>
    @endif

    @if ($digest->endingSoon !== [] || $digest->failing !== [] || $digest->singleShop !== [])
        <section class="mt-8" data-test="worth-a-look">
            <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Worth a look') }}</flux:heading>
            <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Deals about to stop, and products DipCatch cannot follow well.') }}</flux:text>

            <flux:card class="mt-4 p-0!">
                <ul role="list" class="divide-y divide-ink/5">
                    @foreach ($digest->endingSoon as $row)
                        <li class="flex items-start gap-3 px-4 py-3" wire:key="list-ending-{{ $row['shop']->id }}">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-chart-line"></span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('app.products.show', $row['product']) }}" wire:navigate class="block truncate font-medium underline-offset-4 hover:underline">{{ $row['product']->title }}</a>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Deal at :shop ends :when', ['shop' => $row['shop']->host, 'when' => $row['endsAt']->diffForHumans()]) }}</p>
                            </div>
                        </li>
                    @endforeach
                    @foreach ($digest->failing as $row)
                        <li class="flex items-start gap-3 px-4 py-3" wire:key="list-failing-{{ $row['shop']->id }}">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-alert"></span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('app.products.show', $row['product']) }}" wire:navigate class="block truncate font-medium underline-offset-4 hover:underline">{{ $row['product']->title }}</a>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('DipCatch cannot read :shop right now. The page may have moved.', ['shop' => $row['shop']->host]) }}</p>
                            </div>
                        </li>
                    @endforeach
                    @foreach ($digest->singleShop as $product)
                        <li class="flex items-start gap-3 px-4 py-3" wire:key="list-single-{{ $product->id }}">
                            <span class="mt-2 size-2 shrink-0 rounded-full bg-zinc-400"></span>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('app.products.show', $product) }}" wire:navigate class="block truncate font-medium underline-offset-4 hover:underline">{{ $product->title }}</a>
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Tracked at one shop only.') }} <a href="{{ route('app.products.show', [$product, 'add-shop' => 1]) }}" wire:navigate class="font-medium text-brand">{{ __('Add a shop') }}</a></p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        </section>
    @endif

    <section class="mt-8">
        <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Biggest drops') }}</flux:heading>
        <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Cheaper than the price you set, biggest drop first.') }}</flux:text>

        @if ($activeDrops->isEmpty())
            <div class="mt-4 rounded-2xl border border-dashed border-line px-6 py-10 text-center">
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No active drops right now.') }}</flux:text>
                <flux:text size="sm" class="text-zinc-400">{{ __("DipCatch is watching. You hear from us as soon as a price drops far enough.") }}</flux:text>
            </div>
        @else
            <x-product-card.grid class="mt-4">
                @foreach ($activeDrops as $product)
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
        @endif
    </section>

    @if ($watching->isNotEmpty())
        <section class="mt-8">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Recently tracked') }}</flux:heading>
                    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('The last products you added.') }}</flux:text>
                </div>
            </div>

            <x-product-card.grid class="mt-4">
                @foreach ($watching as $product)
                    <li class="min-w-0" wire:key="watching-{{ $product->id }}">
                        <x-product-card :product="$product" :compare="false" />
                    </li>
                @endforeach
            </x-product-card.grid>
        </section>
    @endif
</div>
