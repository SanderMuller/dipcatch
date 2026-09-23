@props(['product', 'limit' => 3])

{{--
    The card's best price comes first and alone in bold. The rest follow by
    price: an out-of-stock or paused shop can be lower, but it is not the
    price the card states.

    z-10 lifts each shop link above the card's stretched link.
--}}
@php($best = $product->cheapestShop)
@php($shops = $product->shops->sortBy(fn ($shop) => [$shop->is($best) ? 0 : 1, $shop->current_price === null ? PHP_FLOAT_MAX : (float) $shop->current_price]))

<ul {{ $attributes->class('space-y-1 text-sm') }}>
    @foreach ($shops->take($limit) as $shop)
        <li class="flex items-center justify-between gap-3">
            @php($isBest = $shop->is($best))
            {{-- The bundle line above the shops already states the best shop's deadline. --}}
            @php($window = $isBest && \App\Support\BundlePriceLabel::forShop($shop) !== null ? null : \App\Support\PromotionLabel::short($shop))
            {{-- Flex, as in x-shop-row-link: inline, the favicon's baseline pushed
                 the deadline below the host. --}}
            <span class="flex min-w-0 items-center gap-x-1 text-zinc-500 dark:text-zinc-400"><a href="{{ $shop->url }}" target="_blank" rel="noopener noreferrer" class="relative z-10 inline-flex shrink-0 items-center hover:underline">{!! \App\Support\Favicon::html($shop->host) !!}</a>@if ($window)<span class="truncate"> · {{ $window }}</span>@endif</span>
            <span @class(['shrink-0 tabular-nums', 'font-semibold text-zinc-900 dark:text-white' => $isBest, 'text-zinc-500 dark:text-zinc-400' => ! $isBest])>
                <x-shop-price :shop="$shop" />
            </span>
        </li>
    @endforeach
    @if ($shops->count() > $limit)
        <li>
            <a href="{{ route('app.products.show', $product) }}" wire:navigate class="text-xs text-zinc-500 dark:text-zinc-400 hover:underline">
                {{ trans_choice('+:count more shop|+:count more shops', $shops->count() - $limit, ['count' => $shops->count() - $limit]) }}
            </a>
        </li>
    @endif
</ul>
