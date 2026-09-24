@props(['product', 'headline'])

{{--
    What the card has no room for, on hover: the discount in full — how much,
    measured on what, since when, and every deal a shop runs — and every shop
    with its price per unit, its pack and its stock. Reads only what the list
    already loaded.
--}}
@php
    $packs = $headline->packs;
    $percent = $product->activeDropPercent();
    $drop = $percent > 0 ? $product->activeDrop() : null;
    $dropUnit = $drop?->comparison_unit;
    // The figure the percentage was measured against; none when it is unknown.
    $dropNow = $dropUnit === null ? null : $product->dropBasisPrice($dropUnit);
    $deals = $product->shops
        ->filter(fn ($shop) => $shop->active)
        ->map(fn ($shop) => ['shop' => $shop, 'deal' => \App\Support\PromotionLabel::runningDeal($shop)])
        ->filter(fn (array $row) => $row['deal'] !== null)
        ->sortBy(fn (array $row) => $row['shop']->is($headline->shop) ? 0 : 1);
    // Every tracked shop, as the card rows: the shops that can be bought from
    // by price per unit, then the rest by pack price.
    $shops = $product->shops
        ->sortBy(fn ($shop) => [
            $headline->isComparable($shop) ? 0 : 1,
            ($headline->isComparable($shop) ? $packs->unitPriceValueOf($shop) : null) ?? ($shop->current_price === null ? PHP_FLOAT_MAX : (float) $shop->current_price),
        ]);
    $unitLabel = \App\Support\UnitWord::labelFor($packs->unit());
@endphp

<div class="w-84 divide-y divide-zinc-950/5 text-left font-normal text-zinc-700 dark:divide-white/10 dark:text-zinc-300" data-test="product-card-details">
    {{-- The product and the figure the card leads with. --}}
    <div class="flex items-center gap-3 pb-3">
        <x-product-thumb :product="$product" size="size-11 shrink-0" />
        <div class="min-w-0">
            <p class="line-clamp-2 text-sm font-medium text-balance text-zinc-950 dark:text-white">{{ $product->title }}</p>
            <p class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                {{ $headline->text() }}@if ($headline->shop) · {{ $headline->shop->host }}@endif
            </p>
        </div>
    </div>

    {{-- The discount, in full. --}}
    <div class="space-y-2 py-3">
        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Discount now') }}</p>

        @if ($drop !== null)
            <div class="flex items-start gap-2.5" data-test="details-drop">
                <span class="shrink-0 rounded-md bg-orange-100 px-1.5 py-0.5 text-xs font-semibold text-orange-700 tabular-nums dark:bg-orange-500/15 dark:text-orange-300">−{{ $percent }}%</span>
                <div class="min-w-0 text-sm">
                    <p class="font-medium text-zinc-950 dark:text-white">
                        {{ $dropUnit === null ? __('Down on the pack price') : __('Down :unit', ['unit' => \App\Support\UnitWord::forCode($dropUnit)]) }}
                        <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ __('since :date', ['date' => $drop->fired_at?->toImmutable()->setTimezone(\App\Support\DutchDate::ZONE)->format('j M')]) }}</span>
                    </p>
                    <p class="text-zinc-500 tabular-nums dark:text-zinc-400">
                        @if ($dropUnit !== null && $drop->reference_unit_price !== null)
                            <del class="decoration-1">{{ \App\Support\MoneyFormatter::unitPrice((string) $drop->reference_unit_price, $drop->currency) }} {{ \App\Support\UnitWord::labelFor($dropUnit) }}</del>
                            @if ($dropNow !== null)
                                → {{ \App\Support\MoneyFormatter::unitPrice($dropNow, $drop->currency) }} {{ \App\Support\UnitWord::labelFor($dropUnit) }}
                            @endif
                        @elseif ($drop->reference_price !== null)
                            {{ __('Was :was a pack, now :now', [
                                'was' => \App\Support\MoneyFormatter::format((string) $drop->reference_price, $drop->currency),
                                'now' => \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $drop->currency),
                            ]) }}
                        @endif
                    </p>
                </div>
            </div>
        @endif

        @foreach ($deals as ['shop' => $dealShop, 'deal' => $dealText])
            <div class="flex items-start gap-2.5 text-sm" data-test="details-deal">
                <span class="flex shrink-0 items-center gap-1 rounded-md bg-amber-100 py-0.5 pr-1.5 pl-1 text-xs font-medium text-amber-800 dark:bg-amber-400/15 dark:text-amber-300">
                    <flux:icon.tag variant="micro" class="size-3.5 shrink-0" />{{ __('Deal') }}
                </span>
                <p class="min-w-0 text-pretty">
                    <span class="font-medium text-zinc-950 dark:text-white">{{ $dealShop->host }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400">· {{ $dealText }}</span>
                </p>
            </div>
        @endforeach

        @if ($drop === null && $deals->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No discount right now.') }}</p>
        @endif
    </div>

    {{-- Every shop, cheapest per unit first. --}}
    @if ($shops->isNotEmpty())
        <div class="space-y-2 pt-3">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ trans_choice(':count shop|:count shops', $shops->count(), ['count' => $shops->count()]) }}</p>
            <ul role="list" class="space-y-2">
                @foreach ($shops as $shop)
                    @php($unitPrice = $headline->isComparable($shop) ? $packs->unitPriceOf($shop) : null)
                    @php($isHeadline = $shop->is($headline->shop))
                    <li class="flex items-start justify-between gap-3 text-sm">
                        <div class="flex min-w-0 items-start gap-2">
                            <img src="{{ \App\Support\Favicon::url($shop->host) }}" alt="" loading="lazy" class="mt-0.5 size-4 shrink-0 rounded-sm" />
                            <div class="min-w-0">
                                <p class="flex items-center gap-1.5">
                                    <span @class(['truncate', 'font-medium text-zinc-950 dark:text-white' => $isHeadline])>{{ $shop->host }}</span>
                                    @if ($isHeadline && $headline->isPerUnit())
                                        <span class="shrink-0 rounded-sm bg-emerald-50 px-1 text-[0.6875rem] font-medium text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">{{ __('Best value') }}</span>
                                    @endif
                                </p>
                                <p class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span @class([
                                        'size-1.5 shrink-0 rounded-full',
                                        'bg-emerald-500' => $shop->active && $shop->current_in_stock === true,
                                        'bg-amber-500' => $shop->active && $shop->current_in_stock === false,
                                        'bg-zinc-400' => ! $shop->active || $shop->current_in_stock === null,
                                    ])></span>
                                    {{ ! $shop->active ? __('Paused') : match ($shop->current_in_stock) { true => __('In stock'), false => __('Out of stock'), null => __('Stock unknown') } }}@if ($shop->priceReadAt()) · {{ $shop->priceReadAt()->diffForHumans(short: true) }}@endif
                                </p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right tabular-nums">
                            @if ($shop->isReference())
                                <p class="text-zinc-500 dark:text-zinc-400">{{ __('Link only') }}</p>
                            @else
                                @if ($unitPrice !== null)
                                    <p @class(['font-medium text-zinc-950 dark:text-white' => $isHeadline])>{{ \App\Support\MoneyFormatter::unitPrice($unitPrice, $shop->currency) }} {{ $unitLabel }}</p>
                                @endif
                                <p @class(['text-xs text-zinc-500 dark:text-zinc-400' => $unitPrice !== null, 'font-medium text-zinc-950 dark:text-white' => $unitPrice === null && $isHeadline])>{{ \App\Support\PackLine::format($shop->current_price === null ? null : (string) $shop->current_price, $shop->currency, $packs->for($shop)->size ?? $shop->packSize()) }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
