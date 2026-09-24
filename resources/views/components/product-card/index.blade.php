@props(['product', 'compare' => true])

{{--
    `compare` lists the shops and notes the lowest pack price. The dashboard
    turns it off: its cards answer what is cheap now and where, and leave the
    comparison to the hover panel, the product list and the product page.
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
    'bg-white shadow-xs ring-1 ring-black/5 hover:shadow-md dark:bg-zinc-900 dark:ring-white/10' => $product->active,
    'border border-dashed border-zinc-300 bg-zinc-100/70 dark:border-white/15 dark:bg-zinc-900/40' => ! $product->active,
]) }}>
    <div class="relative p-3 pb-0">
        <x-product-thumb :product="$product" size="aspect-[4/3] w-full" @class(['opacity-40 grayscale' => ! $product->active]) />
        {{-- Outside the faded image, so the label itself stays readable. --}}
        @if (! $product->active)
            <span class="absolute top-5 right-5 flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-zinc-700 shadow-xs ring-1 ring-black/5 backdrop-blur-sm dark:bg-zinc-800/90 dark:text-zinc-200 dark:ring-white/10" data-test="paused-label">
                <flux:icon.pause-circle variant="micro" class="size-3.5" />
                {{ __('Paused') }}
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
        @endif

        {{-- The deal behind the figure, in the shop's words: a bundle, or a
             promotion window such as "Bonus until 27 Sep". The dashboard's deal
             box below states a bundle itself, so there the line covers the rest. --}}
        @if (($compare || $headline->shop?->liveBundleOffer() === null) && ($deal = \App\Support\PromotionLabel::runningDeal($headline->shop)))
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
                <x-shop-deal :shop="$headline->shop" :show-source="false" />
                {{-- The deal box above already states the deadline. --}}
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
