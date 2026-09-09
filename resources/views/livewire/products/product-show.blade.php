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

        {{-- Fixed height: Chart.js is responsive by default and would otherwise
             grow to whatever the container allows, which ran the chart off the
             fold. The Filament widget capped it at 260px for the same reason. --}}
        <div
            class="mt-4 h-[260px]"
            wire:ignore
            x-data
            x-init="window.dipcatchChart($refs.canvas, @js($series), {{ $chartOptions }})"
        >
            <canvas x-ref="canvas"></canvas>
        </div>
    </flux:card>

    <flux:card class="mt-6">
        {{-- Not "Also sold at": that phrase belongs to the suggestions panel
             below, and a test asserts it is absent when nothing matches. --}}
        <flux:heading size="lg">{{ __('Tracked shops') }}</flux:heading>

        {{-- The add-shop control and the limit explanation, shared with the
             page this replaced: it states the count and the upgrade path
             rather than merely disabling a button. --}}
        <div class="mt-4">
            @include('filament.partials.add-shop-header', [
                'product' => $product,
                'shopLimit' => $shopLimit,
                'canAddShop' => $canAddShop,
            ])
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
                            <td class="py-3 pe-3">
                                {!! \App\Support\Favicon::html($shop->host) !!}
                                @if ($shop->notes)
                                    <flux:tooltip content="{{ $shop->notes }}">
                                        <flux:icon.pencil-square data-slot="notes_indicator" class="ms-1 inline size-3 text-zinc-400" />
                                    </flux:tooltip>
                                @endif
                            </td>
                            <td class="py-3 pe-3">
                                {{ \App\Support\MoneyFormatter::format($shop->current_price === null ? null : (string) $shop->current_price, $shop->currency) }}
                                {{-- A price that is only good until a date says so, or the
                                     number reads as permanent when it is not. --}}
                                @php($promo = \App\Support\PromotionLabel::long($shop))
                                @if ($promo)
                                    <flux:text size="sm" class="text-zinc-500">{{ $promo }}</flux:text>
                                @endif
                                {{-- An offer only some shoppers can claim is named, so the
                                     headline price is not read as everyone's price. --}}
                                @php($conditional = $shop->conditionalOffer())
                                @if ($conditional)
                                    <flux:text size="sm" class="text-zinc-500">
                                        {{ $conditional->label }} · {{ \App\Support\MoneyFormatter::format($conditional->price, $shop->currency) }}
                                    </flux:text>
                                @endif
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
