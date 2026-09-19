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
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('You are following these right now.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active drops') }}</dt>
                <dd @class([
                    'mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl',
                    'text-emerald-600 dark:text-emerald-400' => $activeDropCount > 0,
                ])>{{ $activeDropCount }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Cheaper right now than the price you set.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Saved so far') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $lifetimeSavings }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Compared with the price we alerted you from.') }}</dd>
                <dd class="mt-1">
                    <flux:link :href="route('app.stats')" variant="subtle" class="text-base sm:text-sm" wire:navigate data-test="savings-by-month-link">
                        {{ __('See it by month') }}
                    </flux:link>
                </dd>
            </div>
        </dl>
    </flux:card>

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
                                        <x-shop-price :shop="$product->cheapestShop" :fallback="$product->cheapest_price" :currency="$product->currency" />
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
                                <x-shop-price :shop="$product->cheapestShop" :fallback="$product->cheapest_price" :currency="$product->currency" />
                            </flux:text>
                            @if ($product->cheapestShop)
                                <x-shop-deal :shop="$product->cheapestShop" :show-source="false" class="mt-2 min-w-52 max-w-sm whitespace-normal" />
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">
                            @if ($product->cheapestShop)
                                <x-shop-link :shop="$product->cheapestShop" />
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="py-10 text-center">
                            <flux:text class="text-zinc-500">{{ __('No active drops right now.') }}</flux:text>
                            <flux:text size="sm" class="text-zinc-400">{{ __("DipCatch is watching. You hear from us as soon as a price drops far enough.") }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

</div>
