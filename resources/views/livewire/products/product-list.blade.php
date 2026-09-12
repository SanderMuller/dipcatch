<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Products') }}</flux:heading>
                <flux:badge color="amber" size="lg">{{ $products->total() }}</flux:badge>
            </div>
            <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('Everything you track, cheapest price first.') }}
            </flux:text>
        </div>

        @if ($canAddProduct)
            <flux:button class="rounded-full!" :href="route('app.products.create')" icon="plus" variant="primary" wire:navigate>
                {{ __('Track a product') }}
            </flux:button>
        @else
            {{-- The limit is stated, not hidden: the guard also refuses the write. --}}
            <flux:tooltip content="{{ __('You have reached your plan limit.') }}">
                <flux:button icon="plus" variant="primary" disabled>{{ __('Track a product') }}</flux:button>
            </flux:tooltip>
        @endif
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
        <flux:input
            class="flex-1"
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search your products')"
            clearable
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
            <flux:table.column sortable :sorted="$sort === 'cheapest_price'" :direction="$sort === 'cheapest_price' ? $direction : null" wire:click="sortBy('cheapest_price')">
                {{ __('Cheapest') }}
            </flux:table.column>
            <flux:table.column class="hidden md:table-cell">{{ __('Unit price') }}</flux:table.column>
            <flux:table.column class="hidden md:table-cell">{{ __('Best value') }}</flux:table.column>
            <flux:table.column class="hidden md:table-cell" sortable :sorted="$sort === 'shops_count'" :direction="$sort === 'shops_count' ? $direction : null" wire:click="sortBy('shops_count')">
                {{ __('Shops') }}
            </flux:table.column>
            <flux:table.column align="end">{{ __('Active') }}</flux:table.column>
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
                                    {{ \App\Livewire\Products\ProductList::unitPriceState($product->cheapestShop, $product) }}
                                    · {{ trans_choice(':count shop|:count shops', $product->shops_count, ['count' => $product->shops_count]) }}
                                </flux:text>
                            </div>
                        </a>
                    </flux:table.cell>
                    <flux:table.cell class="tabular-nums">
                        <flux:text class="font-medium">
                            {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                        </flux:text>
                        @php($promo = \App\Support\PromotionLabel::withHost($product->cheapestShop))
                        @if ($promo)
                            <flux:text size="sm" class="text-zinc-500">{{ $promo }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">
                        {{ \App\Livewire\Products\ProductList::unitPriceState($product->cheapestShop, $product) }}
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">
                        {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                        @php($best = \App\Support\PromotionLabel::withHost($product->bestValueShop()))
                        @if ($best)
                            <flux:text size="sm" class="text-zinc-500">{{ $best }}</flux:text>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="hidden tabular-nums md:table-cell">{{ $product->shops_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:tooltip :content="$product->active ? __('Pause tracking') : __('Resume tracking')">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="togglePaused('{{ $product->id }}')"
                                :icon="$product->active ? 'check-circle' : 'pause-circle'"
                                :aria-label="$product->active ? __('Pause tracking') : __('Resume tracking')"
                            />
                        </flux:tooltip>
                    </flux:table.cell>
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
