@props(['product', 'compare' => true])

{{--
    `compare` lists the shops and notes the lowest pack price. The dashboard
    turns it off: it answers what is cheap now and where, and leaves the
    comparison to the product list and the product page.
--}}
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

        @php($headline = \App\Support\HeadlinePrice::of($product))
        <x-product-card.price :product="$product" :headline="$headline" class="mt-3" />

        {{-- The pack behind a per-unit figure, and the deal that sets its price. --}}
        @if ($headline->isPerUnit() && ($packLine = $headline->packLine()))
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400"><x-pack-line :line="$packLine" :bundle="$compare" /></flux:text>
        @elseif ($compare && ($bundleLabel = \App\Support\BundlePriceLabel::forShop($headline->shop)))
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $bundleLabel }}</flux:text>
        @endif

        @if ($compare && $headline->lowestShop)
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400" data-test="lowest-price-note">
                {{ __('Lowest price :line at :host', ['line' => $headline->packLine($headline->lowestShop)?->text(), 'host' => $headline->lowestShop->host]) }}
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
