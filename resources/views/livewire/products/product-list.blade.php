<div>
    <div>
        <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Products') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
            {{ __('Everything you follow, best price first.') }}
        </flux:text>
    </div>

    {{-- The add action sits with the search and the filters, not opposite the
         heading: it belongs to the same row of controls a person works in. --}}
    <div class="mt-6 flex flex-wrap gap-3">
        @if ($canAddProduct)
            <flux:button class="rounded-full!" :href="route('app.products.create')" icon="plus" variant="primary" wire:navigate>
                {{ __('Track a product') }}
            </flux:button>
        @else
            {{-- The limit is stated, not hidden: the guard also refuses the write. --}}
            <flux:tooltip content="{{ __('You are following as many products as the free plan allows.') }}">
                <flux:button class="rounded-full!" icon="plus" variant="primary" disabled>{{ __('Track a product') }}</flux:button>
            </flux:tooltip>
        @endif

        <flux:input
            class="flex-1"
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search your products')"
            clearable
            autocomplete="off"
            data-1p-ignore
        />

        <flux:radio.group variant="segmented" wire:model.live="status" :aria-label="__('Show')">
            <flux:radio value="all">{{ __('All') }}</flux:radio>
            <flux:radio value="active">{{ __('Active') }}</flux:radio>
            <flux:radio value="paused">{{ __('Paused') }}</flux:radio>
        </flux:radio.group>
    </div>

    <flux:table :paginate="$products" class="mt-6">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sort === 'title'" :direction="$sort === 'title' ? $direction : null" wire:click="sortBy('title')">
                {{ __('Product') }}
            </flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column sortable :sorted="$sort === 'cheapest_price'" :direction="$sort === 'cheapest_price' ? $direction : null" wire:click="sortBy('cheapest_price')">
                {{ __('Best price') }}
            </flux:table.column>
            <flux:table.column class="hidden md:table-cell">{{ __('Price per kilo or piece') }}</flux:table.column>
            <flux:table.column class="hidden md:table-cell">{{ __('Best value') }}</flux:table.column>
            <flux:table.column class="hidden md:table-cell" sortable :sorted="$sort === 'shops_count'" :direction="$sort === 'shops_count' ? $direction : null" wire:click="sortBy('shops_count')">
                {{ __('Shops') }}
            </flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($products as $product)
                <flux:table.row :key="'product-'.$product->id">
                    <flux:table.cell>
                        <a href="{{ route('app.products.show', $product) }}" wire:navigate class="flex items-center gap-3">
                            <x-product-thumb :product="$product" size="size-12 sm:size-14" />
                            <div class="min-w-0">
                                <flux:text class="font-medium">{{ Str::limit($product->title, 60) }}</flux:text>
                                {{-- The unit price and the shop count sit in columns that
                                     md: hides, so a phone row carries them here instead
                                     of losing them. --}}
                                <flux:text size="sm" class="text-zinc-500 md:hidden">
                                    <x-shop-price :shop="$product->cheapestShop" unit />
                                    · {{ trans_choice(':count shop|:count shops', $product->shops_count, ['count' => $product->shops_count]) }}
                                </flux:text>
                            </div>
                        </a>
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:tooltip :content="$product->active ? __('Pause tracking') : __('Resume tracking')">
                            <button
                                type="button"
                                wire:click="togglePaused('{{ $product->id }}')"
                                aria-label="{{ $product->active ? __('Pause tracking') : __('Resume tracking') }}"
                                class="cursor-pointer"
                            >
                                <flux:badge
                                    size="sm"
                                    :color="$product->active ? 'green' : 'orange'"
                                    :icon="$product->active ? 'check-circle' : 'pause-circle'"
                                >
                                    {{ $product->active ? __('Active') : __('Paused') }}
                                </flux:badge>
                            </button>
                        </flux:tooltip>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">
                        <flux:text class="font-medium">
                            <x-shop-price :shop="$product->cheapestShop" :fallback="$product->cheapest_price" :currency="$product->currency" />
                        </flux:text>
                        {{-- Flux puts whitespace-nowrap on the whole table, so a long
                             promotion line cannot wrap and widens the column until the
                             page scrolls sideways. These lines wrap inside a capped
                             width instead; the price above them keeps one line. --}}
                        @if ($bundleLabel = \App\Support\BundlePriceLabel::forShop($product->cheapestShop))
                            <flux:text size="sm" class="max-w-xs text-zinc-500 whitespace-normal">{{ $bundleLabel }}</flux:text>
                        @endif
                        {{-- The shop that offers this price, as its own logo and a link
                             out to the page you buy it on: the price is only useful
                             next to the place that charges it. --}}
                        <x-shop-row-link :shop="$product->cheapestShop" :deadline="$bundleLabel === null" />
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">
                        <x-shop-price :shop="$product->cheapestShop" unit />
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">
                        @php($bestValueShop = $product->bestValueShop())
                        <x-shop-price :shop="$bestValueShop" unit />
                        @php($bestBundle = \App\Support\BundlePriceLabel::forShop($bestValueShop))
                        @if ($bestBundle)
                            <flux:text size="sm" class="max-w-xs text-zinc-500 whitespace-normal">{{ $bestBundle }}</flux:text>
                        @endif
                        <x-shop-row-link :shop="$bestValueShop" :deadline="$bestBundle === null" />
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">{{ $product->shops_count }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-10 text-center">
                        <flux:text class="text-zinc-500">
                            {{ $search === '' ? __('Nothing tracked yet.') : __('No product matches that search.') }}
                        </flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
