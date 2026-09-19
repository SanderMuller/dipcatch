<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('app.dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ Str::limit($product->title, 40) }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <x-product-thumb :product="$product" size="size-16 sm:size-20" />
            <div class="min-w-0">
                <flux:heading size="xl" level="1" class="tracking-tight">{{ $product->title }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    {{ trans_choice(':count shop|:count shops', $shops->count(), ['count' => $shops->count()]) }}
                    ·
                    <flux:badge size="sm" :color="$product->active ? 'green' : 'zinc'">
                        {{ $product->active ? __('Active') : __('Paused') }}
                    </flux:badge>
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
                <flux:input
                    :label="__('Public link')"
                    name="share-url"
                    value="{{ $shareUrl }}"
                    readonly
                    copyable
                    class="font-mono"
                    autocomplete="off"
                    data-1p-ignore
                />

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
                        wire:confirm="{{ __('Stop sharing? The link stops working for everyone.') }}"
                    >
                        {{ __('Stop sharing') }}
                    </flux:button>
                </div>

                <flux:text size="sm" class="text-zinc-500">
                    {{ __('A preview someone already has can stay visible for a while after you stop sharing.') }}
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
    <flux:card class="mt-6 p-0! @container">
        <dl class="grid divide-y divide-zinc-950/5 @3xl:grid-cols-3 @3xl:divide-x @3xl:divide-y-0 dark:divide-white/10">
            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Best price now') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                    <x-shop-price :shop="$product->cheapestShop" :fallback="$product->cheapest_price" :currency="$product->currency" />
                </dd>
                @if ($product->cheapestShop)
                    <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                        <x-shop-link :shop="$product->cheapestShop" />
                    </dd>
                    <dd><x-shop-deal :shop="$product->cheapestShop" :show-source="false" class="mt-3" /></dd>
                @endif
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Best value') }}</dt>
                @php($bestValueShop = $product->bestValueShop())
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                    <x-shop-price :shop="$bestValueShop" unit />
                </dd>
                @if ($bestValueShop)
                    <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                        <x-shop-link :shop="$bestValueShop" />
                    </dd>
                    @if ($bestValueShop->id !== $product->cheapestShop?->id)
                        <dd><x-shop-deal :shop="$bestValueShop" :show-source="false" class="mt-3" /></dd>
                    @endif
                @endif
            </div>

            <div class="p-5">
                <dt class="flex items-center gap-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                    <span class="truncate">{{ __('Alerts below') }}</span>
                    {{-- The threshold is the one figure on this page the user
                         sets, so it carries its own way back to the form. --}}
                    <flux:tooltip :content="__('Edit alert threshold')">
                        <flux:button
                            size="xs"
                            variant="subtle"
                            icon="pencil-square"
                            :href="route('app.products.edit', $product)"
                            wire:navigate
                            :aria-label="__('Edit alert threshold')"
                            data-test="edit-alert-threshold"
                        />
                    </flux:tooltip>
                </dt>
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

    <div class="mt-6 grid gap-6 min-[112.5rem]:grid-cols-[13fr_7fr] min-[112.5rem]:items-start">
        <section class="min-w-0">
            <flux:card class="@container">
                {{-- Not "Also sold at": that phrase belongs to the suggestions panel
                     below, and a test asserts it is absent when nothing matches. --}}
                {{-- The heading rides inside the partial so it shares a row with
                     the add-shop button, rather than sitting on a line of its own
                     above it. The partial also states the count and the upgrade
                     path rather than merely disabling a button. --}}
                @include('filament.partials.add-shop-header', [
                    'product' => $product,
                    'shopLimit' => $shopLimit,
                    'canAddShop' => $canAddShop,
                    'openAddShop' => $openAddShop,
                    'heading' => __('Tracked shops'),
                ])

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Shop') }}</flux:table.column>
                        <flux:table.column>{{ __('Price') }}</flux:table.column>
                        <flux:table.column class="hidden @4xl:table-cell">{{ __('Price per kilo or piece') }}</flux:table.column>
                        <flux:table.column class="hidden @4xl:table-cell">{{ __('In stock') }}</flux:table.column>
                        <flux:table.column class="hidden @4xl:table-cell">{{ __('Last checked') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($shops as $shop)
                            <flux:table.row :key="'shop-'.$shop->id">
                                <flux:table.cell class="align-top">
                                    <x-shop-link :shop="$shop" />
                                    @if ($shop->notes)
                                        <flux:tooltip content="{{ $shop->notes }}">
                                            <flux:icon.pencil-square data-slot="notes_indicator" class="ms-1 inline size-3 text-zinc-400" />
                                        </flux:tooltip>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="align-top tabular-nums">
                                    <p class="text-base font-medium text-zinc-900 sm:text-sm dark:text-zinc-100">
                                        <x-shop-price :shop="$shop" />
                                    </p>
                                    {{-- A price that is only good until a date says so, or the
                                         number reads as permanent when it is not. --}}
                                    <x-shop-deal :shop="$shop" class="mt-2 max-w-xl" />
                                    {{-- An offer only some shoppers can claim is named, so the
                                         headline price is not read as everyone's price. --}}
                                    @php($conditional = $shop->conditionalOffer())
                                    @if ($conditional)
                                        <flux:text size="sm" class="text-zinc-500">
                                            {{ $conditional->label }} · {{ \App\Support\MoneyFormatter::format($conditional->price, $shop->currency) }}
                                        </flux:text>
                                    @endif
                                    <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-base text-zinc-500 sm:text-sm @4xl:hidden dark:text-zinc-400">
                                        <x-shop-price :shop="$shop" unit />
                                        <span aria-hidden="true">·</span>
                                        @if ($shop->current_in_stock === true)
                                            <flux:badge size="sm" color="green">{{ __('In stock') }}</flux:badge>
                                        @elseif ($shop->current_in_stock === false)
                                            <flux:badge size="sm" color="amber">{{ __('Out of stock') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Stock unknown') }}</flux:badge>
                                        @endif
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $shop->last_checked_at?->diffForHumans() ?? __('never') }}</span>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="hidden tabular-nums @4xl:table-cell">
                                    <x-shop-price :shop="$shop" unit />
                                </flux:table.cell>
                                <flux:table.cell class="hidden @4xl:table-cell">
                                    @if ($shop->current_in_stock === true)
                                        <flux:badge size="sm" color="green">{{ __('In stock') }}</flux:badge>
                                    @elseif ($shop->current_in_stock === false)
                                        <flux:badge size="sm" color="amber">{{ __('Out of stock') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('Stock unknown') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="hidden text-zinc-500 @4xl:table-cell">
                                    {{ $shop->last_checked_at?->diffForHumans() ?? __('never') }}
                                </flux:table.cell>
                                <flux:table.cell align="end" class="align-top">
                                    <flux:dropdown>
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                                        <flux:menu>
                                            <flux:menu.item icon="arrow-top-right-on-square" :href="$shop->url" target="_blank">
                                                {{ __('Open') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="pencil-square" wire:click="editShop('{{ $shop->id }}')">
                                                {{ __('Edit shop') }}
                                            </flux:menu.item>
                                            <flux:menu.item
                                                icon="exclamation-triangle"
                                                :href="route('app.support', ['type' => \App\Enums\SupportRequestType::ShopIssue->value, 'shop_url' => $shop->url])"
                                                wire:navigate
                                            >
                                                {{ __('Report a problem') }}
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="removeShop('{{ $shop->id }}')" wire:confirm="{{ __('Remove this shop?') }}">
                                                {{ __('Remove') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="py-10 text-center">
                                    <flux:text class="text-zinc-500">{{ __('No shops yet. Add one to start following a price.') }}</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>

                @if ($editingShopId)
                    @php($editing = $shops->firstWhere('id', $editingShopId))
                    @if ($editing)
                        <flux:card class="mt-6">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <flux:heading size="lg">{{ $editing->host }}</flux:heading>
                                    <flux:text class="mt-1 text-zinc-500">
                                        {{ __('Fix the link when the shop moves the product, and keep your own notes about buying there.') }}
                                    </flux:text>
                                </div>

                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('editingShopId', null)" :aria-label="__('Close')" />
                            </div>

                            <div class="mt-4 space-y-4">
                                {{-- Saving the URL re-checks the price on the spot, so it is
                                     its own button rather than part of one save. --}}
                                <div class="space-y-2">
                                    <flux:input wire:model="editingUrl" :label="__('Product link')" type="url" />
                                    <flux:button size="sm" wire:click="saveEditedUrl">{{ __('Save the link and check again') }}</flux:button>
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
                        </flux:card>
                    @endif
                @endif
            </flux:card>
        </section>
        <section class="min-w-0">
            <flux:card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Best price over time') }}</flux:heading>
                        @if ($historyNotice)
                            <flux:text size="sm" class="mt-1 text-zinc-500">
                                {{ $historyNotice['reason'] }}
                                @if ($historyNotice['url'])
                                    <flux:link :href="$historyNotice['url']" wire:navigate>{{ __('Compare plans') }}</flux:link>
                                @endif
                            </flux:text>
                        @endif
                    </div>

                    <flux:select wire:model.live="range" variant="listbox" size="sm" class="max-w-44">
                        @foreach ($ranges as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                @if ($chart['rows'] === [])
                    <flux:text class="mt-4 text-zinc-500">{{ __('No price history yet.') }}</flux:text>
                @else
                    <flux:chart :value="$chart['rows']" class="mt-4 h-72 sm:h-80 min-[112.5rem]:h-120">
                        <flux:chart.svg :gutter="$chart['hasNotified'] ? '52 8 8 8' : '20 8 8 8'">
                            <flux:chart.line field="price" class="text-amber-500 dark:text-amber-400" curve="none" />
                            <flux:chart.area field="price" class="text-amber-200/50 dark:text-amber-400/20" curve="none" />
                            @if ($chart['hasNotified'])
                                <flux:chart.point field="notified" class="text-rose-600 dark:text-rose-400" r="6" />
                            @endif
                            <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'day' => 'numeric']">
                                <flux:chart.axis.tick />
                                <flux:chart.axis.line />
                            </flux:chart.axis>
                            <flux:chart.axis axis="y" :format="['style' => 'currency', 'currency' => $chart['currency']]">
                                <flux:chart.axis.grid />
                                <flux:chart.axis.tick />
                            </flux:chart.axis>
                            <flux:chart.cursor />
                        </flux:chart.svg>
                        <flux:chart.tooltip>
                            <flux:chart.tooltip.heading field="date" :format="['month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit']" />
                            <flux:chart.tooltip.value field="price" :label="__('Best price')" :format="['style' => 'currency', 'currency' => $chart['currency']]" />
                            @if ($chart['hasBundles'])
                                <flux:chart.tooltip.value field="bundle" :label="__('Deal')" />
                            @endif
                            @if ($chart['unitLabel'] !== null)
                                <flux:chart.tooltip.value field="unit" :label="$chart['unitLabel']" :format="['style' => 'currency', 'currency' => $chart['currency']]" />
                            @endif
                            @if ($chart['hasNotified'])
                                <flux:chart.tooltip.value field="notified" :label="__('Notified')" :format="['style' => 'currency', 'currency' => $chart['currency']]" />
                            @endif
                        </flux:chart.tooltip>
                        @if ($chart['hasNotified'])
                            <div class="pointer-events-none absolute inset-x-0 top-3 z-10 flex flex-wrap justify-center gap-x-5 gap-y-2">
                                <flux:chart.legend :label="__('Best price')">
                                    <flux:chart.legend.indicator class="bg-amber-500" />
                                </flux:chart.legend>
                                <flux:chart.legend :label="__('Notified')">
                                    <flux:chart.legend.indicator class="bg-rose-600" />
                                </flux:chart.legend>
                            </div>
                        @endif
                    </flux:chart>
                @endif
            </flux:card>
        </section>

    </div>
</div>
