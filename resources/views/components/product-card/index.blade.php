@props(['product', 'compare' => true, 'listToggle' => false])

{{--
    `compare` lists the shops and notes the lowest pack price. The dashboard
    turns it off: its cards answer what is cheap now and where, and leave the
    comparison to the hover panel, the product list and the product page.

    `listToggle` turns the "On list" label into a button that puts the product
    on the shopping list or takes it off. The parent Livewire component must
    have a `toggleShoppingList(string $productId)` action.
--}}
@php($headline = \App\Support\HeadlinePrice::of($product))

{{-- Hover shows the fuller story: the discount in full and every shop, on the
     list and on the dashboard alike. A visual extra: the card states its
     discount itself and the product page holds the rest, so the panel stays
     hidden from screen readers. `contents`, so the wrapper adds no box and the
     card keeps its grid height. --}}
<flux:tooltip position="right" align="start" class="contents">
<article data-test="product-card" {{ $attributes->class([
    'group relative flex h-full flex-col overflow-hidden rounded-2xl transition',
    'bg-paper shadow-xs ring-1 ring-line hover:shadow-md dark:shadow-none' => $product->active,
    'border border-dashed border-zinc-300 bg-zinc-100/70 dark:border-white/15 dark:bg-zinc-900/40' => ! $product->active,
]) }}>
    <div class="relative p-3 pb-0">
        <x-product-thumb :product="$product"
                         size="aspect-[4/3] w-full"
                         @class(['opacity-40 grayscale' => ! $product->active])
                         data-fly-source />
        {{-- Outside the faded image, so the label itself stays readable. --}}
        @if (! $product->active)
            <span class="absolute top-5 right-5 flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-zinc-700 shadow-xs ring-1 ring-black/5 backdrop-blur-sm dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-white/10" data-test="paused-label">
                <flux:icon.pause-circle variant="micro" class="size-3.5" />
                {{ __('Paused') }}
            </span>
        @endif
        @if ($product->active && $product->isAtTarget())
            <span class="absolute top-5 right-5 flex size-8 items-center justify-center rounded-full bg-white/90 text-emerald-600 shadow-xs ring-1 ring-black/5 backdrop-blur-sm dark:bg-zinc-800/90 dark:text-emerald-400 dark:ring-white/10" data-test="at-target-badge">
                <flux:icon.bell-alert variant="micro" class="size-4" />
                <span class="absolute -top-0.5 -right-0.5 flex size-2.5" aria-hidden="true">
                    <span class="absolute inline-flex size-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span>
                    <span class="relative inline-flex size-2.5 rounded-full bg-emerald-500"></span>
                </span>
                <span class="sr-only">{{ __('At or under your target price') }}</span>
            </span>
        @endif
        @if ($listToggle)
            {{-- Above the link stretched over the card (z-10), so a click adds
                 rather than opens the product. The flight is decoration; the
                 action and its toast carry the change. Both states are 32px
                 tall and ::before widens the hit area, so a second click right
                 after adding still lands here, not on the product link. --}}
            <button
                type="button"
                wire:click="toggleShoppingList('{{ $product->id }}')"
                @unless ($product->isOnShoppingList())
                    x-on:click="window.flyToList($el.closest('article')?.querySelector('[data-fly-source]'))"
                @endunless
                @class([
                    'absolute top-5 left-5 z-10 flex h-8 items-center gap-1 rounded-full text-xs font-medium shadow-xs ring-1 backdrop-blur-sm transition before:absolute before:-inset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 dark:focus-visible:outline-white',
                    'group/list bg-white/90 px-3 text-zinc-700 ring-black/5 hover:bg-red-50 hover:text-red-700 hover:ring-red-200 focus-visible:bg-red-50 focus-visible:text-red-700 dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-white/10 dark:hover:bg-red-950/80 dark:hover:text-red-200 dark:focus-visible:bg-red-950/80 dark:focus-visible:text-red-200' => $product->isOnShoppingList(),
                    'group/add size-8 justify-center bg-white/90 text-zinc-600 ring-black/5 hover:bg-white hover:text-zinc-900 dark:bg-zinc-800/90 dark:text-zinc-300 dark:ring-white/10 dark:hover:text-white' => ! $product->isOnShoppingList(),
                ])
                aria-label="{{ $product->isOnShoppingList() ? __('Remove :title from shopping list', ['title' => $product->title]) : __('Add :title to shopping list', ['title' => $product->title]) }}"
                data-test="card-list-toggle"
            >
                @if ($product->isOnShoppingList())
                    {{-- Says what a click does once the pointer or focus is on it. --}}
                    <span class="flex items-center gap-1 group-hover/list:hidden group-focus-visible/list:hidden" aria-hidden="true">
                        <flux:icon.check variant="micro" class="size-3.5" />
                        {{ __('On list') }}
                    </span>
                    <span class="hidden items-center gap-1 group-hover/list:flex group-focus-visible/list:flex" aria-hidden="true">
                        <flux:icon.x-mark variant="micro" class="size-3.5" />
                        {{ __('Remove') }}
                    </span>
                @else
                    <flux:icon.list-bullet variant="micro" class="size-4" />
                    {{-- Styled like a Flux tooltip; the button's own name already
                         says this to a screen reader. --}}
                    <span
                        class="pointer-events-none absolute top-full left-0 mt-2 rounded-md bg-zinc-800 px-2 py-1 text-xs font-medium whitespace-nowrap text-white opacity-0 shadow-sm transition-opacity duration-150 group-hover/add:opacity-100 group-hover/add:delay-200 group-focus-visible/add:opacity-100 dark:bg-white dark:text-zinc-900"
                        aria-hidden="true"
                        data-test="card-list-tooltip"
                    >{{ __('Add to shopping list') }}</span>
                @endif
            </button>
        @elseif ($product->isOnShoppingList())
            <span class="absolute top-5 left-5 flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-zinc-700 shadow-xs ring-1 ring-black/5 backdrop-blur-sm dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-white/10" data-test="on-list-label">
                <flux:icon.list-bullet variant="micro" class="size-3.5" />
                <span aria-hidden="true">{{ __('On list') }}</span>
                <span class="sr-only">{{ __('On your shopping list') }}</span>
            </span>
        @endif
    </div>

    <div @class(['flex min-w-0 flex-1 flex-col p-4', 'opacity-60' => ! $product->active])>
        {{-- Stretched over the whole card. The shop links sit above it with z-10. --}}
        <a href="{{ route('app.products.show', $product) }}" wire:navigate class="line-clamp-2 font-medium text-zinc-900 group-hover:underline after:absolute after:inset-0 after:rounded-2xl focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-zinc-900 dark:text-white dark:focus-visible:after:outline-white">
            {{ $product->title }}
        </a>
        @if ($product->category !== null)
            <span class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400" data-test="product-category-badge">{{ $product->category->label() }}</span>
        @endif

        <x-product-card.price :product="$product" :headline="$headline" class="mt-3" />

        {{-- The pack behind a per-unit figure, and during a bundle the regular
             price per unit — named, on this line rather than beside the drop
             badge, where a struck figure reads as the price before the drop. --}}
        @if ($headline->isPerUnit() && ($packLine = $headline->packLine()))
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                <x-pack-line :line="$packLine" :bundle="false" />@if ($regularUnit = $headline->regularUnitPrice()) · {{ __('regular') }} <del title="{{ __('Regular price') }}" class="decoration-1">{{ \App\Support\MoneyFormatter::unitPrice($regularUnit, $headline->currency()) }} {{ \App\Support\UnitWord::labelFor($headline->unit) }}</del>@endif
            </flux:text>
        @elseif ($unitLine = $headline->unitLine())
            {{-- Under a pack price, what it comes to per kilo, litre or piece.
                 A drop measured per unit states its old figure here, in its
                 own unit: its old pack price may be another pack's. --}}
            @php($unitDrop = $product->activeDropPercent() > 0 ? $product->activeDrop() : null)
            <flux:text size="sm" class="text-zinc-500 tabular-nums dark:text-zinc-400" data-test="card-unit-line">
                {{ $unitLine }}@if ($unitDrop?->comparison_unit !== null && $unitDrop->comparison_unit === $headline->packs->unit() && $unitDrop->reference_unit_price !== null) · {{ __('was :price', ['price' => \App\Support\MoneyFormatter::unitPrice((string) $unitDrop->reference_unit_price, $unitDrop->currency) . ' ' . \App\Support\UnitWord::labelFor($unitDrop->comparison_unit)]) }}@endif
            </flux:text>
        @endif

        {{-- The deal behind the figure, in the shop's words: a bundle, or a
             promotion window such as "Bonus until 27 Sep". The dashboard's deal
             box below states a bundle itself, so there the line covers the rest. --}}
        @php($deal = ($compare || $headline->shop?->liveBundleOffer() === null) ? \App\Support\PromotionLabel::runningDeal($headline->shop) : null)
        @if ($deal)
            <flux:text size="sm" class="mt-1 text-zinc-600 dark:text-zinc-300" data-test="card-deal">
                <flux:badge size="sm" color="amber" class="me-1">{{ __('Deal') }}</flux:badge>{{ $deal }}
            </flux:text>
        @endif

        @if ($compare && ($lowest = $headline->lowestShop))
            @php($lowestDeal = \App\Support\PromotionLabel::runningDeal($lowest))
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400" data-test="lowest-price-note">
                {{ __('Lowest price :line at :host', ['line' => \App\Support\PackLine::format($lowest->current_price === null ? null : (string) $lowest->current_price, $lowest->currency, $headline->packLine($lowest)?->size), 'host' => $lowest->host]) }}@if ($lowestDeal) · <span class="text-zinc-600 dark:text-zinc-300">{{ $lowestDeal }}</span>@endif
            </flux:text>
        @endif

        {{ $slot }}

        @if ($compare)
            <x-product-card.shops :product="$product" :headline="$headline" class="mt-auto pt-4" />
        @elseif ($headline->shop)
            <div class="mt-auto space-y-2 pt-4">
                {{-- The deal line or the deal box states any deadline, once: with
                     a running deal on the line the box would only repeat the
                     date, and the row link leaves it out either way. --}}
                @unless ($deal)
                    <x-shop-deal :shop="$headline->shop" :show-source="false" />
                @endunless
                @php($hasDeal = $headline->shop->liveBundleOffer() !== null || $headline->shop->promotionWindow() !== null)
                <div class="relative z-10 w-fit">
                    <x-shop-row-link :shop="$headline->shop" :deadline="! $hasDeal" />
                </div>
            </div>
        @endif
    </div>
</article>

<flux:tooltip.content class="rounded-xl! bg-white! p-4! shadow-lg ring-1 ring-zinc-950/10 dark:border-0! dark:bg-zinc-900! dark:shadow-none dark:ring-white/10">
    <x-product-card.details :product="$product" :headline="$headline" />
</flux:tooltip.content>
</flux:tooltip>
