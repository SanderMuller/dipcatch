@props(['product', 'compare' => true])

{{--
    `compare` lists every shop with the unit price and the best value. The
    dashboard turns it off: it answers what is cheap now and where, and
    leaves the comparison to the product list and the product page.
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
            <span class="mt-0.5 text-xs text-zinc-500" data-test="product-category-badge">{{ $product->category->label() }}</span>
        @endif

        <x-product-card.price :product="$product" class="mt-3" />

        @if ($compare)
            {{-- No line without a pack size: a lone dash here reads as a broken card. --}}
            @if ($product->cheapestShop?->unitPrice() !== null)
                <flux:text size="sm" class="text-zinc-500"><x-shop-price :shop="$product->cheapestShop" unit /></flux:text>
            @endif
            @if ($bundleLabel = \App\Support\BundlePriceLabel::forShop($product->cheapestShop))
                <flux:text size="sm" class="text-zinc-500">{{ $bundleLabel }}</flux:text>
            @endif

            @php($bestValueShop = $product->bestValueShop())
            @if ($bestValueShop !== null && $bestValueShop->isNot($product->cheapestShop))
                <flux:text size="sm" class="mt-1 text-zinc-500">
                    {{ __('Best value') }}: <x-shop-price :shop="$bestValueShop" unit class="font-medium text-zinc-700 dark:text-zinc-300" /> · {{ $bestValueShop->host }}
                </flux:text>
            @endif
        @endif

        {{ $slot }}

        @if ($compare)
            <x-product-card.shops :product="$product" class="mt-auto pt-4" />
        @elseif ($product->cheapestShop)
            <div class="mt-auto space-y-2 pt-4">
                <x-shop-deal :shop="$product->cheapestShop" :show-source="false" />
                {{-- The deal box above already states the deadline. --}}
                @php($hasDeal = $product->cheapestShop->liveBundleOffer() !== null || $product->cheapestShop->promotionWindow() !== null)
                <div class="relative z-10 w-fit">
                    <x-shop-row-link :shop="$product->cheapestShop" :deadline="! $hasDeal" />
                </div>
            </div>
        @endif
    </div>
</article>
