@props(['product', 'headline', 'limit' => 3])

{{--
    The shop behind the card's figure comes first and alone in bold. The shops
    that can be bought from follow by price per unit, then everything else by
    pack price, so the best value never falls off the short list and a sold-out
    or trade-only row never outranks a live one. A row states a price per unit
    only on a size its own page states.

    z-10 lifts each shop link above the card's stretched link.
--}}
@php($best = $headline->shop)
@php($packs = $headline->packs)
@php($perUnit = $headline->isPerUnit())
@php($comparable = $headline->isComparable(...))
@php($shops = $product->shops->sortBy(fn ($shop) => [
    $shop->is($best) ? 0 : 1,
    $comparable($shop) ? 0 : 1,
    ($comparable($shop) ? $packs->unitPriceValueOf($shop) : null) ?? ($shop->current_price === null ? PHP_FLOAT_MAX : (float) $shop->current_price),
]))

<ul {{ $attributes->class('@container space-y-1 text-sm') }}>
    @foreach ($shops->take($limit) as $shop)
        <li class="flex items-center justify-between gap-3">
            @php($isBest = $shop->is($best))
            {{-- The bundle line above the shops already states the best shop's deadline. --}}
            @php($window = $isBest && \App\Support\BundlePriceLabel::forShop($shop) !== null ? null : \App\Support\PromotionLabel::short($shop))
            {{-- Flex, as in x-shop-row-link: inline, the favicon's baseline pushed
                 the deadline below the host. --}}
            {{-- The host gives way before the prices do: it truncates, they never wrap. --}}
            <span class="flex min-w-0 items-center gap-x-1 text-zinc-500 dark:text-zinc-400"><a href="{{ $shop->url }}" target="_blank" rel="noopener noreferrer" class="relative z-10 inline-flex min-w-0 items-center gap-1.5 hover:underline"><img src="{{ \App\Support\Favicon::url($shop->host) }}" alt="" loading="lazy" class="size-4 flex-none rounded" /><span class="truncate">{{ $shop->host }}</span></a>@if ($window)<span class="truncate"> · {{ $window }}</span>@endif</span>
            <span @class(['flex shrink-0 items-baseline gap-x-1.5 tabular-nums', 'font-semibold text-zinc-900 dark:text-white' => $isBest, 'text-zinc-500 dark:text-zinc-400' => ! $isBest])>
                @if ($comparable($shop) && ($unitPrice = $packs->unitPriceOf($shop)) !== null)
                    <span>{{ \App\Support\MoneyFormatter::unitPrice($unitPrice, $shop->currency) }} {{ \App\Support\UnitWord::labelFor($headline->unit) }}</span>
                    {{-- Only where the row has room: a narrow card keeps the figure it compares on. --}}
                    <span class="hidden text-xs font-normal text-zinc-500 @[17rem]:inline dark:text-zinc-400">{{ \App\Support\MoneyFormatter::format((string) $shop->current_price, $shop->currency) }}</span>
                @else
                    <x-shop-price :shop="$shop" />
                @endif
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
