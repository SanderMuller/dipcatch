<div>
    <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Dashboard') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
        {{ __('What you track, and what it has saved you so far.') }}
    </flux:text>

    {{-- One surface with dividers, not three cards: these three numbers are
         siblings in one context, so they need separation, not elevation. --}}
    <flux:card class="mt-6 p-0!">
        <dl class="grid divide-y divide-zinc-950/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10">
            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Tracked products') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $trackedProducts }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active on your watch list.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active drops') }}</dt>
                <dd @class([
                    'mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl',
                    'text-emerald-600 dark:text-emerald-400' => $activeDropCount > 0,
                ])>{{ $activeDropCount }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Below your threshold right now.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Lifetime savings') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $lifetimeSavings }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Against the price each alert fired from.') }}</dd>
            </div>
        </dl>
    </flux:card>

    @unless ($hasAnyProduct)
        <flux:callout class="mt-6" icon="sparkles">
            <flux:callout.heading>{{ __('Track your first product') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Paste a product URL and DipCatch will watch it across every shop that sells it.') }}</flux:callout.text>
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
            <flux:button class="mt-3" :href="route('app.products.show', $watching->first())" variant="primary" wire:navigate>
                {{ __('Open a product') }}
            </flux:button>
        </flux:callout>
    @endif

    @if ($watching->isNotEmpty())
        <div class="mt-8">
            <div class="flex items-end justify-between gap-3">
                <flux:heading size="lg" level="2">{{ __('Recently tracked') }}</flux:heading>
                <flux:link :href="route('app.products.index')" wire:navigate>{{ __('All products') }}</flux:link>
            </div>

            {{-- Cards here, dividers above: each tile navigates on its own, and
                 a card is the treatment for an independently interactive item. --}}
            <div class="@container mt-4">
                <ul role="list" class="grid gap-4 @md:grid-cols-2 @3xl:grid-cols-3">
                    @foreach ($watching as $product)
                        {{-- min-w-0: a grid track sizes to its content by default,
                             so a long title pushed the card past the viewport
                             on a phone. --}}
                        <li class="min-w-0" wire:key="watching-{{ $product->id }}">
                            {{-- An anchor carrying the card styling, not flux:card:
                                 that component always renders a div, so an href on
                                 it produces a tile nobody can click. --}}
                            <a
                                href="{{ route('app.products.show', $product) }}"
                                wire:navigate
                                class="flex h-full items-center gap-4 rounded-2xl bg-white/80 p-4 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white dark:bg-zinc-900/60 dark:ring-zinc-800 dark:hover:bg-zinc-900"
                            >
                                <x-product-thumb :product="$product" size="size-14" />
                                <div class="min-w-0">
                                    <flux:text class="truncate font-medium">{{ Str::limit($product->title, 40) }}</flux:text>
                                    <flux:text size="sm" class="truncate text-zinc-500 tabular-nums">
                                        {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                                        @if ($product->cheapestShop)
                                            · {{ $product->cheapestShop->host }}
                                        @endif
                                    </flux:text>
                                    @if ($bundleLabel = \App\Support\BundlePriceLabel::forShop($product->cheapestShop))
                                        <flux:text size="sm" class="truncate text-zinc-500">{{ $bundleLabel }}</flux:text>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="mt-8">
        <flux:heading size="lg" level="2">{{ __('Active drops') }}</flux:heading>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('Now') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Best value') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Notified at') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Shop') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($activeDrops as $product)
                    <flux:table.row :key="'drop-'.$product->id">
                        <flux:table.cell>
                            <a href="{{ route('app.products.show', $product) }}" wire:navigate class="flex items-center gap-3">
                                <x-product-thumb :product="$product" size="size-12" />
                                <flux:text class="font-medium">{{ Str::limit($product->title, 60) }}</flux:text>
                            </a>
                        </flux:table.cell>
                        <flux:table.cell class="tabular-nums">
                            <flux:text class="font-medium text-emerald-600 dark:text-emerald-400">
                                {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                            </flux:text>
                            @if ($bundleLabel = \App\Support\BundlePriceLabel::forShop($product->cheapestShop))
                                <flux:text size="sm" class="text-zinc-500">{{ $bundleLabel }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden tabular-nums md:table-cell">
                            @php($bestValueShop = $product->bestValueShop())
                            {{ \App\Livewire\Products\ProductList::unitPriceState($bestValueShop, $product) }}
                            @php($bestBundleLabel = \App\Support\BundlePriceLabel::forShop($bestValueShop))
                            @php($bestLabel = $bestBundleLabel === null ? \App\Support\PromotionLabel::withHost($bestValueShop) : $bestValueShop?->host . ' · ' . $bestBundleLabel)
                            @if ($bestLabel)
                                <flux:text size="sm" class="text-zinc-500">{{ $bestLabel }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden tabular-nums text-zinc-500 md:table-cell">
                            {{ \App\Support\MoneyFormatter::format($product->last_notified_price === null ? null : (string) $product->last_notified_price, $product->currency) }}
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            @if ($product->cheapestShop) {!! \App\Support\Favicon::html($product->cheapestShop->host) !!} @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="py-10 text-center">
                            <flux:text class="text-zinc-500">{{ __('No active drops right now.') }}</flux:text>
                            <flux:text size="sm" class="text-zinc-400">{{ __("DipCatch is watching. We'll alert you when a price drops below your threshold.") }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @if ($savings)
        <div class="mt-8">
            <flux:heading size="lg" level="2">{{ __('Savings by month') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('Summed across every alert that fired, over the last twelve months. One bar per currency, never converted.') }}
            </flux:text>

            <flux:card class="mt-4">
                <flux:chart :value="$savings['rows']" class="aspect-[3/1]">
                    <flux:chart.svg>
                        @if (count($savings['series']) > 1)
                            <flux:chart.group>
                                @foreach ($savings['series'] as $series)
                                    <flux:chart.bar :field="$series['field']" :class="$series['color']" />
                                @endforeach
                            </flux:chart.group>
                        @else
                            @foreach ($savings['series'] as $series)
                                <flux:chart.bar :field="$series['field']" :class="$series['color']" />
                            @endforeach
                        @endif
                        <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'year' => '2-digit']">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-start="0">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.cursor type="area" />
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" :format="['month' => 'long', 'year' => 'numeric']" />
                        @foreach ($savings['series'] as $series)
                            <flux:chart.tooltip.value :field="$series['field']" :label="$series['label']" :format="['style' => 'currency', 'currency' => $series['currency']]" />
                        @endforeach
                    </flux:chart.tooltip>
                    @if (count($savings['series']) > 1)
                        <div class="flex flex-wrap justify-center gap-4 pt-4">
                            @foreach ($savings['series'] as $series)
                                <flux:chart.legend :label="$series['label']">
                                    <flux:chart.legend.indicator :class="$series['legend']" />
                                </flux:chart.legend>
                            @endforeach
                        </div>
                    @endif
                </flux:chart>
            </flux:card>
        </div>
    @endif

    @if ($recentAlerts->isNotEmpty())
        <div class="mt-8">
            <flux:heading size="lg" level="2">{{ __('Recent alerts') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('What DipCatch has told you, most recent first. The bell clears itself; this does not.') }}
            </flux:text>

            <flux:timeline class="mt-4">
                @foreach ($recentAlerts as $alert)
                    <flux:timeline.item wire:key="alert-{{ $loop->index }}">
                        <flux:timeline.indicator color="green">
                            <flux:icon.arrow-trending-down variant="micro" />
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:heading>
                                @if ($alert['url'])
                                    <a href="{{ $alert['url'] }}" wire:navigate>{{ Str::limit($alert['title'], 60) }}</a>
                                @else
                                    {{ Str::limit($alert['title'], 60) }}
                                @endif
                                @if ($alert['sentAt'])
                                    <flux:text inline>· {{ $alert['sentAt'] }}</flux:text>
                                @endif
                            </flux:heading>
                            <flux:text class="tabular-nums">
                                {{ $alert['percent'] ?? '—' }}
                                @if ($alert['amount'])
                                    · {{ $alert['amount'] }}
                                @endif
                                @if ($alert['bundle'])
                                    · {{ $alert['bundle'] }}
                                @endif
                            </flux:text>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </div>
    @endif
</div>
