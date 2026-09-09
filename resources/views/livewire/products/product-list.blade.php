<div>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="xl">{{ __('Products') }}</flux:heading>

        @if ($canAddProduct)
            <flux:button :href="route('app.products.create')" icon="plus" variant="primary" wire:navigate>
                {{ __('Track a product') }}
            </flux:button>
        @else
            {{-- The limit is stated, not hidden: the guard also refuses the write. --}}
            <flux:tooltip content="{{ __('You have reached your plan limit.') }}">
                <flux:button icon="plus" variant="primary" disabled>{{ __('Track a product') }}</flux:button>
            </flux:tooltip>
        @endif
    </div>

    <flux:input
        class="mt-4"
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        :placeholder="__('Search your products')"
        clearable
    />

    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-start text-sm">
            <thead class="border-b border-zinc-200 dark:border-zinc-700">
                <tr>
                    <th class="py-2 pe-3 text-start font-medium">
                        <button type="button" wire:click="sortBy('title')" class="cursor-pointer">{{ __('Product') }}</button>
                    </th>
                    <th class="py-2 pe-3 text-start font-medium">
                        <button type="button" wire:click="sortBy('cheapest_price')" class="cursor-pointer">{{ __('Cheapest') }}</button>
                    </th>
                    <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Unit price') }}</th>
                    <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Best value') }}</th>
                    <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">
                        <button type="button" wire:click="sortBy('shops_count')" class="cursor-pointer">{{ __('Shops') }}</button>
                    </th>
                    <th class="py-2 text-end font-medium">{{ __('Active') }}</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($products as $product)
                    <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="product-{{ $product->id }}">
                        <td class="py-3 pe-3">
                            <a href="{{ route('app.products.show', $product) }}" wire:navigate class="flex items-center gap-3">
                                @if ($product->image_url)
                                    <img src="{{ $product->image_url }}" alt="" class="size-9 shrink-0 rounded object-cover" loading="lazy" />
                                @endif
                                <flux:text class="font-medium">{{ Str::limit($product->title, 60) }}</flux:text>
                            </a>
                        </td>
                        <td class="py-3 pe-3">
                            {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                            @php($promo = \App\Support\PromotionLabel::withHost($product->cheapestShop))
                            @if ($promo)
                                <flux:text size="sm" class="text-zinc-500">{{ $promo }}</flux:text>
                            @endif
                        </td>
                        <td class="hidden py-3 pe-3 md:table-cell">
                            {{ \App\Livewire\Products\ProductList::unitPriceState($product->cheapestShop, $product) }}
                        </td>
                        <td class="hidden py-3 pe-3 md:table-cell">
                            {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                            @php($best = \App\Support\PromotionLabel::withHost($product->bestValueShop()))
                            @if ($best)
                                <flux:text size="sm" class="text-zinc-500">{{ $best }}</flux:text>
                            @endif
                        </td>
                        <td class="hidden py-3 pe-3 md:table-cell">{{ $product->shops_count }}</td>
                        <td class="py-3 text-end">
                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="togglePaused('{{ $product->id }}')"
                                :icon="$product->active ? 'check-circle' : 'pause-circle'"
                                :aria-label="$product->active ? __('Pause tracking') : __('Resume tracking')"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-10 text-center">
                            <flux:text class="text-zinc-500">
                                {{ $search === '' ? __('Nothing tracked yet.') : __('No product matches that search.') }}
                            </flux:text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</div>
