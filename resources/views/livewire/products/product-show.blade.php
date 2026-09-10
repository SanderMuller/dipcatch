<div>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <x-product-thumb :product="$product" size="size-16 sm:size-20" />
            <div class="min-w-0">
                <flux:heading size="xl" class="tracking-tight">{{ $product->title }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ trans_choice(':count shop|:count shops', $shops->count(), ['count' => $shops->count()]) }}
                    · {{ $product->active ? __('Active') : __('Paused') }}
                </flux:text>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <flux:button size="sm" wire:click="togglePaused" :icon="$product->active ? 'pause' : 'play'">
                {{ $product->active ? __('Pause tracking') : __('Resume tracking') }}
            </flux:button>

            <flux:modal.trigger name="sharing">
                <flux:button size="sm" icon="share">{{ __('Sharing') }}</flux:button>
            </flux:modal.trigger>

            <flux:button size="sm" variant="primary" class="rounded-full!" icon="pencil-square" :href="route('app.products.edit', $product)" wire:navigate>
                {{ __('Edit') }}
            </flux:button>
        </div>
    </div>

    <flux:modal name="sharing" class="w-full max-w-lg">
        <div class="space-y-6 text-start">
            <div>
                <flux:heading size="lg">{{ __('Public sharing') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ __('Anyone with the link sees the product, its prices and the shops. Your notes, the add-shop form and everything about your account stay private.') }}
                </flux:text>
            </div>

            @if ($shareMessage)
                <flux:callout icon="information-circle">{{ $shareMessage }}</flux:callout>
            @endif

            @if ($shareUrl)
                {{-- Readonly and selected on focus: this is a value to copy,
                     not a field to edit. --}}
                <div class="space-y-2" x-data="{ copied: false }">
                    <flux:input
                        :label="__('Public link')"
                        value="{{ $shareUrl }}"
                        readonly
                        x-ref="shareUrl"
                        x-on:focus="$event.target.select()"
                        class="font-mono"
                    />
                    <flux:button
                        size="sm"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText($refs.shareUrl.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                    >
                        <span x-show="! copied">{{ __('Copy link') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </flux:button>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-zinc-950/5 pt-4 dark:border-white/10">
                    <flux:button
                        size="sm"
                        wire:click="rotateShareLink"
                        wire:confirm="{{ __('Replace the link? The current one stops working immediately.') }}"
                    >
                        {{ __('Replace link') }}
                    </flux:button>

                    <flux:button
                        size="sm"
                        variant="danger"
                        wire:click="stopSharing"
                        wire:confirm="{{ __('Stop sharing? The link will return a 404.') }}"
                    >
                        {{ __('Stop sharing') }}
                    </flux:button>
                </div>

                <flux:text size="sm" class="text-zinc-500">
                    {{ __('A preview someone already has can stay visible for a while after you stop.') }}
                </flux:text>
            @else
                <flux:text>{{ __('This product has no public link yet.') }}</flux:text>

                <flux:button size="sm" variant="primary" class="rounded-full!" wire:click="generateShareLink">
                    {{ __('Create a public link') }}
                </flux:button>
            @endif
        </div>
    </flux:modal>

    @if ($shopMessage)
        <flux:callout class="mt-6" icon="information-circle">{{ $shopMessage }}</flux:callout>
    @endif

    {{-- Three sibling numbers on one surface, divided rather than boxed. --}}
    <flux:card class="mt-6 p-0!">
        <dl class="grid divide-y divide-zinc-950/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10">
            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Cheapest now') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                    {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                </dd>
                @if ($product->cheapestShop)
                    <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ $product->cheapestShop->host }}</dd>
                @endif
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Best value') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                    {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                </dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Alerts below') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                    @if ($product->target_price !== null)
                        {{ \App\Support\MoneyFormatter::format((string) $product->target_price, $product->currency) }}
                    @elseif ($product->drop_threshold_pct !== null)
                        {{ $product->drop_threshold_pct }}%
                    @else
                        {{ __('Any drop') }}
                    @endif
                </dd>
            </div>
        </dl>
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
                <thead class="border-b border-zinc-950/10 dark:border-white/10">
                    <tr>
                        <th class="py-2 pe-3 text-start font-medium whitespace-nowrap">{{ __('Shop') }}</th>
                        <th class="py-2 pe-3 text-start font-medium whitespace-nowrap">{{ __('Price') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium whitespace-nowrap md:table-cell">{{ __('Unit price') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium whitespace-nowrap md:table-cell">{{ __('In stock') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium whitespace-nowrap md:table-cell">{{ __('Last checked') }}</th>
                        <th class="py-2 text-end font-medium whitespace-nowrap">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($shops as $shop)
                        <tr class="border-b border-zinc-950/5 dark:border-white/5" wire:key="shop-{{ $shop->id }}">
                            <td class="py-3 pe-3">
                                {!! \App\Support\Favicon::html($shop->host) !!}
                                @if ($shop->notes)
                                    <flux:tooltip content="{{ $shop->notes }}">
                                        <flux:icon.pencil-square data-slot="notes_indicator" class="ms-1 inline size-3 text-zinc-400" />
                                    </flux:tooltip>
                                @endif
                            </td>
                            <td class="py-3 pe-3 tabular-nums">
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
                            <td class="hidden py-3 pe-3 tabular-nums md:table-cell">
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
                                    icon="pencil-square"
                                    wire:click="editShop('{{ $shop->id }}')"
                                    :aria-label="__('Edit shop')"
                                />

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

        @if ($editingShopId)
            @php($editing = $shops->firstWhere('id', $editingShopId))
            @if ($editing)
                <div class="mt-6 rounded-2xl bg-white/80 p-6 ring-1 ring-zinc-200 dark:bg-zinc-900/60 dark:ring-zinc-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <flux:heading size="lg">{{ $editing->host }}</flux:heading>
                            <flux:text class="mt-1 text-zinc-500">
                                {{ __('Repair the link when the shop moves the product, and keep your own notes about buying there.') }}
                            </flux:text>
                        </div>

                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('editingShopId', null)" :aria-label="__('Close')" />
                    </div>

                    <div class="mt-4 space-y-4">
                        {{-- Saving the URL re-checks the price on the spot, so it is
                             its own button rather than part of one save. --}}
                        <div class="space-y-2">
                            <flux:input wire:model="editingUrl" :label="__('Product URL')" type="url" />
                            <flux:button size="sm" wire:click="saveEditedUrl">{{ __('Save URL and re-check') }}</flux:button>
                        </div>

                        <div class="space-y-2">
                            <flux:textarea
                                wire:model="editingNotes"
                                :label="__('Notes')"
                                :placeholder="__('Ships only to NL, coupon CODE10, free delivery over 30 euro…')"
                                rows="3"
                            />
                            <flux:button size="sm" wire:click="saveEditedNotes">{{ __('Save notes') }}</flux:button>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </flux:card>
</div>
