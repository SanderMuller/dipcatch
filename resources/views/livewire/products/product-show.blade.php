<div>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            @if ($product->image_url)
                <img src="{{ $product->image_url }}" alt="" class="size-16 rounded object-cover" />
            @endif
            <div>
                <flux:heading size="xl">{{ $product->title }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ trans_choice(':count shop|:count shops', $shops->count(), ['count' => $shops->count()]) }}
                    · {{ $product->active ? __('Active') : __('Paused') }}
                </flux:text>
            </div>
        </div>

        <flux:button size="sm" wire:click="togglePaused" :icon="$product->active ? 'pause' : 'play'">
            {{ $product->active ? __('Pause tracking') : __('Resume tracking') }}
        </flux:button>
    </div>

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('Price') }}</flux:heading>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Cheapest now') }}</flux:text>
                <flux:heading size="lg">
                    {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                </flux:heading>
                @if ($product->cheapestShop)
                    <flux:text size="sm" class="text-zinc-500">{{ $product->cheapestShop->host }}</flux:text>
                @endif
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Best value') }}</flux:text>
                <flux:heading size="lg">
                    {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                </flux:heading>
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Alerts below') }}</flux:text>
                <flux:heading size="lg">
                    @if ($product->target_price !== null)
                        {{ \App\Support\MoneyFormatter::format((string) $product->target_price, $product->currency) }}
                    @elseif ($product->drop_threshold_pct !== null)
                        {{ $product->drop_threshold_pct }}%
                    @else
                        {{ __('Any drop') }}
                    @endif
                </flux:heading>
            </div>
        </div>
    </flux:card>

    <flux:card class="mt-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">{{ __('Cheapest price history') }}</flux:heading>
                @if ($historyNotice)
                    <flux:text size="sm" class="mt-1 text-zinc-500">
                        {{ $historyNotice['reason'] }}
                        @if ($historyNotice['url'])
                            <flux:link :href="$historyNotice['url']" wire:navigate>{{ __('Compare plans') }}</flux:link>
                        @endif
                    </flux:text>
                @endif
            </div>

            <flux:select wire:model.live="range" size="sm" class="max-w-44">
                @foreach ($ranges as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div
            class="mt-4"
            wire:ignore
            x-data
            x-init="window.dipcatchChart($refs.canvas, @js($series), {{ $chartOptions }})"
        >
            <canvas x-ref="canvas" height="260"></canvas>
        </div>
    </flux:card>

    <flux:card class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Also sold at') }}</flux:heading>

            @if ($canAddShop)
                <flux:button size="sm" icon="plus" x-on:click="$dispatch('open-add-shop')">{{ __('Add a shop') }}</flux:button>
            @else
                <flux:tooltip content="{{ __('Your plan allows no more shops for this product.') }}">
                    <flux:button size="sm" icon="plus" disabled>{{ __('Add a shop') }}</flux:button>
                </flux:tooltip>
            @endif
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Shop') }}</th>
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Price') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Unit price') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('In stock') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Last checked') }}</th>
                        <th class="py-2 text-end font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shops as $shop)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="shop-{{ $shop->id }}">
                            <td class="py-3 pe-3">{!! \App\Support\Favicon::html($shop->host) !!}</td>
                            <td class="py-3 pe-3">
                                {{ \App\Support\MoneyFormatter::format($shop->current_price === null ? null : (string) $shop->current_price, $shop->currency) }}
                            </td>
                            <td class="hidden py-3 pe-3 md:table-cell">
                                {{ \App\Livewire\Products\ProductList::unitPriceState($shop, $product) }}
                            </td>
                            <td class="hidden py-3 pe-3 md:table-cell">
                                {{ $shop->current_in_stock ? __('Yes') : __('No') }}
                            </td>
                            <td class="hidden py-3 pe-3 text-zinc-500 md:table-cell">
                                {{ $shop->last_checked_at?->diffForHumans() ?? __('never') }}
                            </td>
                            <td class="py-3 text-end">
                                <flux:button size="xs" variant="ghost" icon="arrow-top-right-on-square" :href="$shop->url" target="_blank" :aria-label="__('Open')" />
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="removeShop('{{ $shop->id }}')"
                                    wire:confirm="{{ __('Remove this shop?') }}"
                                    :aria-label="__('Remove')"
                                />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-10 text-center">
                                <flux:text class="text-zinc-500">{{ __('No shops yet. Add one to start tracking a price.') }}</flux:text>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
