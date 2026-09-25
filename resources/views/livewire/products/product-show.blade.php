@php
    // Resolved once: every shop row asks the same resolver, so two rows on one
    // page can never disagree about which shops are comparable.
    $packs = $product->comparablePacks();
@endphp

<div>
    {{-- The name shortens with an ellipsis rather than wrapping the trail and pushing Edit below. --}}
    <div class="mb-4 flex items-center justify-between gap-3">
        <flux:breadcrumbs class="min-w-0">
            <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item class="min-w-0 [&>div]:min-w-0"><span class="block truncate">{{ Str::limit($product->title, 40) }}</span></flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <flux:button size="sm" variant="primary" class="rounded-full!" icon="pencil-square" :href="route('app.products.edit', $product)" wire:navigate>
            {{ __('Edit') }}
        </flux:button>
    </div>

    @php($headline = \App\Support\HeadlinePrice::of($product, $packs))
    @php($headlineShop = $headline->shop)
    @php($regularUnit = $headline->isPerUnit() ? $headline->regularUnitPrice() : null)
    @php($belowRegular = $headline->belowRegularPercent())
    {{-- A drop the price has climbed back out of reads as −0%: shown as none, as on the product cards. --}}
    @php($dropPercent = $product->activeDropPercent())
    @php($activeDrop = $dropPercent > 0 ? $product->activeDrop() : null)
    <flux:card class="@container sm:p-6!">
        <div class="flex flex-col gap-5 @2xl:flex-row @2xl:items-start @2xl:gap-14">
            {{-- The pills render twice, and a container query shows one set: here
                 beside the photo on a narrow card, in the title row on a wide one. --}}
            <div class="flex items-start justify-between gap-4">
                <x-product-thumb :product="$product" size="size-32 @md:size-40 @2xl:size-52" />
                <x-product-status-pills :product="$product" :share-url="$shareUrl" class="flex-col items-end @2xl:hidden" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <flux:heading size="xl" level="1" class="text-2xl! font-semibold! tracking-tight sm:text-3xl!">{{ $product->title }}</flux:heading>
                        {{-- A wrapping row with gaps rather than "·" separators: on a
                             narrow screen a separator is left dangling at a line end.
                             A div, not flux:text: a badge is a div, and a div inside
                             a <p> closes the paragraph before it. --}}
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400" data-test="product-meta">
                            <span>{{ trans_choice(':count shop|:count shops', $shops->count(), ['count' => $shops->count()]) }}</span>
                            @if ($product->category !== null)
                                <a href="{{ route('app.products.index', ['category' => $product->category->value]) }}" wire:navigate data-test="product-category-badge">
                                    <flux:badge size="sm" color="zinc">{{ $product->category->label() }}</flux:badge>
                                </a>
                            @else
                                <a href="{{ route('app.products.index', ['category' => \App\Livewire\Products\ProductList::NO_CATEGORY]) }}" wire:navigate data-test="product-category-none">
                                    <flux:badge size="sm" color="zinc" icon="question-mark-circle">{{ __('No category') }}</flux:badge>
                                </a>
                            @endif
                        </div>
                    </div>

                    <x-product-status-pills :product="$product" :share-url="$shareUrl" class="flex-wrap items-center justify-end @max-2xl:hidden" />
                </div>

                <dl class="mt-5 grid gap-4 rounded-2xl p-4 ring-1 ring-line @md:p-5 @xl:grid-cols-[1fr_auto] @xl:items-center">
                    <div data-test="headline-price">
                        <dt class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ $headline->isPerUnit() ? __('Best value') : __('Best price now') }}</dt>
                        <dd class="mt-1 text-3xl font-semibold tracking-tight tabular-nums @md:text-4xl">
                            @if ($headline->isPerUnit())
                                <span class="inline-flex flex-wrap items-baseline gap-x-3">
                                    <span>{{ $headline->text() }}</span>
                                    @if ($regularUnit)
                                        <del title="{{ __('Regular price') }}" class="text-lg font-normal text-zinc-400 decoration-1 dark:text-zinc-500">{{ \App\Support\MoneyFormatter::unitPrice($regularUnit, $headline->currency()) }} {{ \App\Support\UnitWord::labelFor($headline->unit) }}</del>
                                    @endif
                                </span>
                            @else
                                <x-shop-price :shop="$headlineShop" :fallback="$product->cheapest_price" :currency="$product->currency" />
                            @endif
                        </dd>
                        @if ($headlineShop)
                            <dd class="mt-1 flex flex-wrap items-center gap-x-2 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                                @if ($headline->isPerUnit())
                                    <x-pack-line :line="$headline->packLine()" :bundle="false" />
                                    <span aria-hidden="true">·</span>
                                @endif
                                <x-shop-link :shop="$headlineShop" />
                            </dd>
                            <dd><x-shop-deal :shop="$headlineShop" :show-source="false" class="mt-3" /></dd>
                        @endif

                        {{-- A note, not a warning: the headline already names the
                             better buy, so the lowest pack price is context. --}}
                        @if ($headline->lowestShop)
                            @php($unitGap = $headline->lowestCostsMorePercent())
                            <dd class="mt-3 text-base text-zinc-500 sm:text-sm dark:text-zinc-400" data-test="lowest-price-note">
                                {{ __('Lowest price: :line at', ['line' => $headline->packLine($headline->lowestShop)?->text()]) }}
                                {{-- Written inline rather than as x-shop-link, whose trailing
                                     newline would put a space before the period. --}}
                                <a href="{{ $headline->lowestShop->url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center align-bottom hover:underline underline-offset-4">{!! \App\Support\Favicon::html($headline->lowestShop->host) !!}</a>.
                                @if ($unitGap !== null)
                                    {{ __('That is :percent% more :unit than the best value.', [
                                        'percent' => $unitGap,
                                        'unit' => \App\Support\UnitWord::forCode($headline->unit) ?? __('per unit'),
                                    ]) }}
                                @endif
                            </dd>
                        @endif
                    </div>

                    {{-- The drop an alert fired on first, as the product cards show it;
                         failing that, what a running bundle saves on the single price. --}}
                    @if ($activeDrop !== null)
                        <div class="flex flex-row-reverse items-baseline justify-end gap-3 rounded-xl bg-savings/10 px-4 py-3 text-savings-strong @xl:flex-col-reverse @xl:items-stretch @xl:gap-0 @xl:px-5 @xl:py-4" data-test="active-drop">
                            <dt class="text-base sm:text-sm @xl:mt-1">{{ $activeDrop->wasLabel() }}</dt>
                            <dd class="flex items-center gap-1 text-2xl font-semibold tracking-tight tabular-nums @xl:text-3xl">
                                <flux:icon.arrow-down class="size-6 shrink-0" />
                                {{ $dropPercent }}%
                            </dd>
                        </div>
                    @elseif ($belowRegular !== null)
                        <div class="flex flex-row-reverse items-baseline justify-end gap-3 rounded-xl bg-savings/10 px-4 py-3 text-savings-strong @xl:flex-col-reverse @xl:items-stretch @xl:gap-0 @xl:px-5 @xl:py-4" data-test="below-regular">
                            <dt class="text-base sm:text-sm @xl:mt-1">{{ __('below the regular price') }}</dt>
                            <dd class="flex items-center gap-1 text-2xl font-semibold tracking-tight tabular-nums @xl:text-3xl">
                                <flux:icon.arrow-down class="size-6 shrink-0" />
                                {{ $belowRegular }}%
                            </dd>
                        </div>
                    @else
                        <div class="flex flex-row-reverse items-baseline justify-end gap-3 rounded-xl bg-ink/5 px-4 py-3 text-zinc-500 @xl:flex-col-reverse @xl:items-stretch @xl:gap-0 @xl:px-5 @xl:py-4 dark:text-zinc-400" data-test="no-drop">
                            <dt class="text-base sm:text-sm @xl:mt-1">{{ __('No drop right now') }}</dt>
                            <dd class="text-2xl font-semibold tracking-tight @xl:text-3xl" aria-hidden="true">&ndash;</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>
    </flux:card>

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

    <div class="mt-6 grid gap-6 min-[112.5rem]:grid-cols-[3fr_2fr] min-[112.5rem]:items-start">
        <section class="min-w-0 space-y-6">
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
                    'heading' => __('Shop comparison'),
                    'subheading' => $packs->hasComparisonUnit()
                        ? __('Same product, different pack sizes. Sorted by price :unit.', ['unit' => \App\Support\UnitWord::forCode($packs->unit())])
                        : __('Sorted by price.'),
                ])

                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Shop') }}</flux:table.column>
                        <flux:table.column class="hidden @2xl:table-cell">{{ __('Pack size') }}</flux:table.column>
                        <flux:table.column>{{ $packs->hasComparisonUnit() ? __('Price :unit', ['unit' => \App\Support\UnitWord::forCode($packs->unit())]) : __('Price') }}</flux:table.column>
                        <flux:table.column class="hidden @4xl:table-cell">{{ __('In stock') }}</flux:table.column>
                        <flux:table.column class="hidden @4xl:table-cell">{{ __('Price read') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        {{-- Cheapest per unit first, then the shops outside that
                             comparison by pack price. --}}
                        @php($shops = $shops->sortBy(fn ($shop) => [
                            $packs->unitPriceValueOf($shop) === null ? 1 : 0,
                            $packs->unitPriceValueOf($shop) ?? ($shop->current_price === null ? PHP_FLOAT_MAX : (float) $shop->current_price),
                        ])->values())
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
                                {{-- The size the comparison uses: the shop's own, or the one
                                     it borrowed from its siblings, which the unit price beside
                                     it marks as estimated. --}}
                                @php($packLine = \App\Support\PackLine::of($shop, $packs))
                                <flux:table.cell class="hidden align-top tabular-nums @2xl:table-cell" data-test="shop-pack-size">
                                    @if ($packLine->size !== null)
                                        {{ \App\Support\UnitWord::pack($packLine->size) }}
                                    @else
                                        <span aria-hidden="true" class="text-zinc-400">&ndash;</span>
                                        <span class="sr-only">{{ __('Unknown') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="align-top tabular-nums" data-test="shop-price-cell">
                                    {{-- Per unit first: the figure the shops are compared on. The
                                         pack price beneath it is what the shop charges. --}}
                                    @if ($packs->hasComparisonUnit() && ! $shop->isReference())
                                        @php($packPriceHint = $packLine->size === null || $packLine->estimated
                                            ? __('Pack price: what :shop charges for one pack.', ['shop' => $shop->host])
                                            : __('Pack price: what :shop charges for :size.', ['shop' => $shop->host, 'size' => \App\Support\UnitWord::pack($packLine->size)]))
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-base font-medium text-zinc-900 sm:text-sm dark:text-zinc-100">
                                            @if ($packs->unitPriceOf($shop) !== null)
                                                <flux:tooltip :content="__('Price :unit: the pack price divided by the pack size, so packs of different sizes compare fairly.', ['unit' => \App\Support\UnitWord::forCode($packs->unit())])">
                                                    <span tabindex="0" class="cursor-help rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-test="unit-price-hint"><x-shop-unit-price :shop="$shop" :packs="$packs" /></span>
                                                </flux:tooltip>
                                            @else
                                                <x-shop-unit-price :shop="$shop" :packs="$packs" />
                                            @endif
                                            {{-- The shop the headline above names, marked in the row so
                                                 the table and the card never disagree. --}}
                                            @if ($headline->isPerUnit() && $headlineShop?->id === $shop->id && $shops->count() > 1)
                                                <span class="rounded-full bg-savings/10 px-2 py-0.5 text-xs font-medium text-savings-strong" data-test="best-value-chip">{{ __('Best value') }}</span>
                                            @endif
                                        </div>
                                        {{-- The size rides along until the table is wide enough
                                             for a column of its own. --}}
                                        {{-- Divs, not paragraphs: the tooltip renders a <div>, and a
                                             <div> inside a <p> closes the paragraph and strands it. --}}
                                        <div class="mt-0.5 text-base text-zinc-500 sm:text-sm @2xl:hidden dark:text-zinc-400">
                                            <flux:tooltip :content="$packPriceHint">
                                                <span tabindex="0" class="cursor-help rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"><x-pack-line :line="$packLine" :bundle="false" :marker="false" /></span>
                                            </flux:tooltip>
                                        </div>
                                        <div class="mt-0.5 hidden text-base text-zinc-500 sm:text-sm @2xl:block dark:text-zinc-400">
                                            <flux:tooltip :content="$packPriceHint">
                                                <span tabindex="0" class="cursor-help rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand" data-test="pack-price-hint">{{ \App\Support\MoneyFormatter::format($packLine->price, $shop->currency) }}</span>
                                            </flux:tooltip>
                                        </div>
                                    @else
                                        <p class="text-base font-medium text-zinc-900 sm:text-sm dark:text-zinc-100">
                                            <x-shop-price :shop="$shop" />
                                        </p>
                                        @if ($shop->isReference() || $shop->notAConsumerPriceReason() !== null)
                                            {{-- A link's note, or a price nobody here pays. --}}
                                            <p class="mt-0.5 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                                                <x-shop-unit-price :shop="$shop" :packs="$packs" />
                                            </p>
                                        @endif
                                    @endif
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
                                        @if ($shop->current_in_stock === true)
                                            <flux:badge size="sm" color="green">{{ __('In stock') }}</flux:badge>
                                        @elseif ($shop->current_in_stock === false)
                                            <flux:badge size="sm" color="amber">{{ __('Out of stock') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('Stock unknown') }}</flux:badge>
                                        @endif
                                        <span aria-hidden="true">·</span>
                                        <x-shop-freshness :shop="$shop" />
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="hidden align-top @4xl:table-cell" data-test="shop-stock-cell">
                                    @php($stock = match ($shop->current_in_stock) {
                                        true => ['label' => __('In stock'), 'icon' => 'check-circle', 'class' => 'text-savings-strong'],
                                        false => ['label' => __('Out of stock'), 'icon' => 'x-circle', 'class' => 'text-amber-600 dark:text-amber-500'],
                                        default => ['label' => __('Stock unknown'), 'icon' => 'question-mark-circle', 'class' => 'text-zinc-400'],
                                    })
                                    <flux:tooltip :content="$stock['label']">
                                        <span class="inline-flex">
                                            <flux:icon :icon="$stock['icon']" variant="micro" :class="'size-4 shrink-0 ' . $stock['class']" />
                                            <span class="sr-only">{{ $stock['label'] }}</span>
                                        </span>
                                    </flux:tooltip>
                                </flux:table.cell>
                                <flux:table.cell class="hidden align-top @4xl:table-cell">
                                    <x-shop-freshness :shop="$shop" />
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

            <flux:card data-test="alerts-card">
                <div class="flex items-center gap-1">
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Alerts') }}</flux:heading>
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
                </div>
                <flux:text size="sm" class="mt-0.5 text-zinc-500">{{ __('DipCatch tells you when a price meets one of these.') }}</flux:text>
                <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums" data-test="alert-rules">
                    {{ $alertRules[0]['value'] ?? __('Any drop') }}
                    @if ($alertRules[0]['pro'] ?? false)
                        <flux:badge size="sm" color="zinc" class="align-middle">{{ __('Pro') }}</flux:badge>
                    @endif
                </p>
                @if ($alertRules[0]['below'] ?? false)
                    <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('when a price reaches it') }}</p>
                @endif
                {{-- One line per rule, so every rule set on the form shows. --}}
                @foreach (array_slice($alertRules, 1) as $rule)
                    <p class="mt-1 text-base font-medium tabular-nums text-zinc-600 sm:text-sm dark:text-zinc-300">
                        {{ $rule['below'] ? __('or :value or less', ['value' => $rule['value']]) : __('or :value', ['value' => $rule['value']]) }}
                        @if ($rule['pro'] ?? false)
                            <flux:badge size="sm" color="zinc">{{ __('Pro') }}</flux:badge>
                        @endif
                    </p>
                @endforeach
                @if ($awaitsConfirmation)
                    <p class="mt-3 flex items-start gap-1.5 text-base text-zinc-500 sm:text-sm dark:text-zinc-400" data-test="confirming-drop">
                        <flux:icon.clock variant="micro" class="mt-1 size-4 shrink-0 sm:mt-0.5" />
                        <span>{{ __('Confirming a large drop. The alert follows once a second reading agrees.') }}</span>
                    </p>
                @endif
            </flux:card>
        </section>
        <section class="min-w-0">
            @php($perUnit = \App\Support\UnitWord::forCode($chart['unit']))
            {{-- A current price with no pack size has no point on the per-unit line,
                 and a line that only began at the last check is one dot on an
                 empty chart. Both open on the pack price. --}}
            @php($basis = $perUnit !== null && is_float(array_last($chart['rows'])['unit'] ?? null) && $chart['unitCoverage'] >= \App\Charts\UnitLineCoverage::READABLE ? 'unit' : 'price')
            <flux:card x-data="{ basis: '{{ $basis }}' }" wire:key="price-history-{{ $perUnit === null ? 'pack' : 'both' }}-{{ $basis }}-{{ $range }}" data-test="price-history">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Price over time') }}</flux:heading>
                        @if ($perUnit === null)
                            <flux:text size="sm" class="mt-0.5 text-zinc-500">{{ __('Price per pack, at the shop with the lowest price.') }}</flux:text>
                        @else
                            <flux:text size="sm" class="mt-0.5 text-zinc-500" x-show="basis === 'unit'" :x-cloak="$basis !== 'unit'">{{ __('Price :unit, at the shop that is the best value.', ['unit' => $perUnit]) }}</flux:text>
                            <flux:text size="sm" class="mt-0.5 text-zinc-500" x-show="basis === 'price'" :x-cloak="$basis !== 'price'">{{ __('Price per pack, at the shop with the lowest price.') }}</flux:text>
                        @endif
                        @if ($historyNotice)
                            <flux:text size="sm" class="mt-1 text-zinc-500">
                                {{ $historyNotice['reason'] }}
                                @if ($historyNotice['url'])
                                    <flux:link :href="$historyNotice['url']" wire:navigate>{{ __('Compare plans') }}</flux:link>
                                @endif
                            </flux:text>
                        @endif
                    </div>

                    @php($shortRanges = ['30' => __('1M'), '90' => __('3M'), '365' => __('1Y'), 'all' => __('All')])
                    <flux:radio.group variant="segmented" size="sm" wire:model.live="range" :aria-label="__('Range')" data-test="price-history-range">
                        @foreach ($ranges as $value => $label)
                            <flux:radio value="{{ $value }}" :aria-label="__($label)">{{ $shortRanges[$value] ?? __($label) }}</flux:radio>
                        @endforeach
                    </flux:radio.group>
                </div>

                @if ($perUnit !== null)
                    <div class="mt-3 flex items-center justify-end gap-2">
                        <flux:radio.group variant="segmented" size="sm" x-model="basis" :aria-label="__('Show')" data-test="price-history-basis">
                            <flux:radio value="unit">{{ __('Best value') }}</flux:radio>
                            <flux:radio value="price">{{ __('Lowest price') }}</flux:radio>
                        </flux:radio.group>
                        <flux:tooltip :content="__('Lowest price follows the lowest pack price. That can be a small pack that costs more :unit than a bigger one.', ['unit' => $perUnit])">
                            <flux:button icon="information-circle" size="sm" variant="subtle" inset :aria-label="__('About lowest price')" />
                        </flux:tooltip>
                    </div>
                @endif

                @if ($chart['rows'] === [])
                    <flux:text class="mt-4 text-zinc-500">{{ __('No price history yet.') }}</flux:text>
                @else
                    @php($currency = ['style' => 'currency', 'currency' => $chart['currency']])
                    @php($unitCurrency = $chart['unitDecimals'] > 2 ? [...$currency, 'maximumFractionDigits' => $chart['unitDecimals']] : $currency)
                    @php($packLabel = __('Lowest price'))
                    @php($unitLabel = $perUnit === null ? null : __('Best value :unit', ['unit' => $perUnit]))
                    @foreach ($perUnit === null ? ['price'] : ['unit', 'price'] as $field)
                        @php($isUnit = $field === 'unit')
                        @php($notifiedField = $isUnit ? 'notifiedUnit' : 'notified')
                        <div wire:key="price-history-{{ $field }}" x-show="basis === '{{ $field }}'" @if ($field !== $basis) x-cloak @endif>
                            <flux:chart :value="$chart['rows']" class="mt-4 h-72 sm:h-80 min-[112.5rem]:h-120" data-test="price-history-chart-{{ $field }}">
                                <flux:chart.svg :gutter="$chart['hasNotified'] ? '52 8 8 8' : '20 8 8 8'">
                                    <flux:chart.area :field="$field" class="text-chart/15 dark:text-chart/20" curve="none" />
                                    <flux:chart.line :field="$field" class="text-chart-line" stroke-width="2" curve="none" />
                                    @if ($chart['hasNotified'])
                                        <flux:chart.point :field="$notifiedField" class="text-alert" r="5" stroke-width="3" />
                                    @endif
                                    <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'day' => 'numeric']">
                                        <flux:chart.axis.tick class="text-xs text-zinc-400" />
                                        <flux:chart.axis.line class="text-ink/10" />
                                    </flux:chart.axis>
                                    @php($yTicks = \App\Charts\ChartScale::ticks(array_values(array_filter(array_column($chart['rows'], $field), is_float(...))), $isUnit ? $chart['unitDecimals'] : 2))
                                    <flux:chart.axis axis="y" :tick-start="$yTicks[0] ?? 'min'" :tick-end="array_last($yTicks) ?? 'auto'" :tick-values="$yTicks === [] ? null : $yTicks" :format="$isUnit ? $unitCurrency : $currency">
                                        <flux:chart.axis.grid class="text-ink/5" />
                                        <flux:chart.axis.tick class="text-xs text-zinc-400" />
                                    </flux:chart.axis>
                                    <flux:chart.cursor class="text-ink/20" stroke-dasharray="4 4" />
                                </flux:chart.svg>
                                <flux:chart.tooltip>
                                    <flux:chart.tooltip.heading field="date" :format="['month' => 'short', 'day' => 'numeric', 'hour' => 'numeric', 'minute' => '2-digit']" />
                                    @if ($isUnit)
                                        <flux:chart.tooltip.value field="unit" :label="$unitLabel" :format="$unitCurrency" />
                                        <flux:chart.tooltip.value field="price" :label="$packLabel" :format="$currency" />
                                    @else
                                        <flux:chart.tooltip.value field="price" :label="$packLabel" :format="$currency" />
                                        @if ($unitLabel !== null)
                                            <flux:chart.tooltip.value field="unit" :label="$unitLabel" :format="$unitCurrency" />
                                        @endif
                                    @endif
                                    @if ($chart['hasBundles'])
                                        <flux:chart.tooltip.value field="bundle" :label="__('Deal')" />
                                    @endif
                                    @if ($chart['hasNotified'])
                                        <flux:chart.tooltip.value field="notified" :label="__('Notified')" :format="$currency" />
                                    @endif
                                </flux:chart.tooltip>
                                {{-- The last alert, labelled on the chart without a hover, as
                                     the brand kit shows it. Flux plots the point, so the label
                                     finds that point once it is drawn, and again whenever
                                     the chart redraws or changes size. --}}
                                @php($lastNotified = array_last(array_filter($chart['rows'], fn (array $row): bool => isset($row[$notifiedField]))))
                                @if ($lastNotified !== null)
                                    <div
                                        x-data="{
                                            left: null,
                                            top: 0,
                                            place() {
                                                const chart = $el.parentElement;

                                                if (! chart) {
                                                    return;
                                                }

                                                const point = [...chart.querySelectorAll('circle[data-point][data-series={{ $notifiedField }}]')]
                                                    .sort((a, b) => a.getAttribute('cx') - b.getAttribute('cx'))
                                                    .pop();
                                                const box = chart.getBoundingClientRect();
                                                const dot = point?.getBoundingClientRect();

                                                if (! dot || dot.width === 0) {
                                                    this.left = null;

                                                    return;
                                                }

                                                const centre = dot.left + dot.width / 2 - box.left;
                                                this.left = Math.min(Math.max(centre - $el.offsetWidth / 2, 4), box.width - $el.offsetWidth - 4);
                                                this.top = Math.max(0, dot.top - box.top - $el.offsetHeight - 8);
                                            },
                                        }"
                                        x-init="
                                            $nextTick(() => requestAnimationFrame(() => place()));
                                            new ResizeObserver(() => place()).observe($el.parentElement);
                                            new MutationObserver(() => place()).observe($el.parentElement, { childList: true, subtree: true, attributeFilter: ['cx', 'cy'] });
                                        "
                                        x-bind:class="left === null && 'invisible'"
                                        x-bind:style="{ left: `${left ?? 0}px`, top: `${top}px` }"
                                        class="pointer-events-none absolute z-10 rounded-lg bg-paper px-2.5 py-1.5 shadow-lg shadow-ink/10 ring-1 ring-line dark:shadow-none"
                                        data-test="notified-callout-{{ $field }}"
                                    >
                                        <p class="text-xs font-medium text-alert">{{ __('Notified') }}</p>
                                        <p class="text-sm font-semibold whitespace-nowrap tabular-nums">
                                            {{-- The price the alert stated, on either line: the per-unit
                                                 point only marks when it fired. --}}
                                            {{ \App\Support\MoneyFormatter::format((string) $lastNotified['notified'], $chart['currency']) }}
                                        </p>
                                    </div>
                                @endif
                                @if ($chart['hasNotified'])
                                    <div class="pointer-events-none absolute inset-x-0 top-3 z-10 flex flex-wrap justify-center gap-x-5 gap-y-2">
                                        <flux:chart.legend :label="$isUnit ? __('Best value') : $packLabel">
                                            <flux:chart.legend.indicator class="bg-chart-line" />
                                        </flux:chart.legend>
                                        <flux:chart.legend :label="__('Notified')">
                                            <flux:chart.legend.indicator class="bg-alert" />
                                        </flux:chart.legend>
                                    </div>
                                @endif
                            </flux:chart>
                        </div>
                    @endforeach
                @endif
            </flux:card>
        </section>

    </div>
</div>
