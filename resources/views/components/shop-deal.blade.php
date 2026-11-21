@props([
    'shop',
    'showSource' => true,
    // A {@see \App\Support\DiscountCheck}: the product page passes one, the
    // product cards never do.
    'beforeDiscount' => null,
    // A {@see \App\Support\ComparablePacks}: with it an announced bundle
    // states its price per unit, comparable with the other rows.
    'packs' => null,
])

@php
    $liveBundle = $shop->liveBundleOffer();
    $singleItemPrice = $shop->singleItemPrice();
    $window = $shop->promotionWindow();
    $isUpcoming = $window?->hasNotStarted() ?? false;
    $bundle = $isUpcoming ? $shop->bundleOffer() : $liveBundle;
    // An announced bundle per unit, beside the rows it will compete with. A
    // running one needs none: the row's own figure already holds the deal.
    // Only where the row leads with a price per unit, so both read alike.
    $pack = $isUpcoming && $packs?->leadingItems() === null ? $packs?->for($shop) : null;
    $dealUnitPrice = $bundle === null || $pack === null || $pack->isExcluded() ? null : $pack->unitPriceFor(bcdiv($bundle->totalPrice, (string) $bundle->quantity, 4));
    // A bare amount is a reader's own label (see UpcomingDeal), not the shop page's words.
    $sourceOffer = is_numeric($window?->label) ? null : $window?->label;
    $deadline = $window === null ? null : \App\Support\PromotionLabel::short($shop);
    $surfaceClasses = $isUpcoming
        ? 'bg-blue-50/70 ring-blue-900/10 dark:bg-blue-950/25 dark:ring-blue-200/10'
        : 'bg-amber-50/70 ring-amber-900/10 dark:bg-amber-950/25 dark:ring-amber-200/10';
@endphp

@if ($bundle !== null || $window !== null || $beforeDiscount !== null)
    <div {{ $attributes->class("space-y-2 rounded-lg p-3 whitespace-normal ring-1 {$surfaceClasses}") }}>
        @if ($isUpcoming)
            {{-- Announced, not running: say what it gives, at which shop and
                 when, so it cannot be read as today's price. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-blue-700 dark:text-blue-300">
                <flux:icon.calendar-days class="size-4" />
                <flux:badge size="sm" color="blue">{{ __('Upcoming deal') }}</flux:badge>
            </div>
            <p class="text-base font-medium text-zinc-900 sm:text-sm dark:text-zinc-100" data-test="upcoming-deal-terms">
                {{ \App\Support\PromotionLabel::upcomingTerms($shop) }}
                <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ __('at :host', ['host' => $shop->displayName()]) }}</span>
            </p>
            @if ($dealUnitPrice !== null)
                <p class="flex flex-wrap items-baseline gap-x-2 text-base text-zinc-700 tabular-nums sm:text-sm dark:text-zinc-300" data-test="deal-unit-price">
                    <span>{{ __('In the deal: :price', ['price' => \App\Support\MoneyFormatter::unitPrice($dealUnitPrice, $shop->currency) . ' ' . $pack->size?->label()]) }}</span>
                    {{-- The same marker as the row: a size taken from the other shops is an estimate. --}}
                    @if (in_array($pack->provenance, [\App\Enums\PackProvenance::Inferred, \App\Enums\PackProvenance::Converted], true))
                        <flux:badge size="sm" color="zinc" data-test="deal-unit-price-estimated">{{ __('estimated') }}</flux:badge>
                    @endif
                </p>
            @endif
            @if ($bundle !== null && $singleItemPrice !== null)
                {{-- Not struck through: until the deal starts this is what a shopper pays. --}}
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                    {{ __('Until then: :price each', ['price' => \App\Support\MoneyFormatter::format($singleItemPrice, $shop->currency)]) }}
                </p>
            @endif
            <p class="text-base text-zinc-500 tabular-nums sm:text-sm dark:text-zinc-400">{{ \App\Support\PromotionLabel::period($window) }}</p>
        @endif

        @if ($bundle !== null && ! $isUpcoming)
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                @unless ($isUpcoming)
                    <flux:badge size="sm" color="amber">{{ __('Deal') }}</flux:badge>
                @endunless
                <p class="text-base font-medium tabular-nums text-zinc-900 sm:text-sm dark:text-zinc-100">
                    {{ \App\Support\BundlePriceLabel::condition($bundle, $shop->currency) }}
                </p>
                @if ($singleItemPrice !== null)
                    <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                        {{ __('Normal price') }}: <del title="{{ __('Regular price') }}">{{ \App\Support\MoneyFormatter::format($singleItemPrice, $shop->currency) }}</del> {{ __('each') }}
                    </p>
                @endif
                @if (! $showSource && $deadline !== null && ! $isUpcoming)
                    <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ $deadline }}</p>
                @endif
            </div>
        @endif

        @if (! $showSource && $bundle === null && $deadline !== null && ! $isUpcoming)
            <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ $deadline }}</p>
        @endif

        @if ($showSource && $window !== null && ! $isUpcoming)
            <div @class([
                'border-t pt-2' => $bundle !== null,
                'border-blue-900/10 dark:border-blue-200/10' => $bundle !== null && $isUpcoming,
                'border-amber-900/10 dark:border-amber-200/10' => $bundle !== null && ! $isUpcoming,
            ])>
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                    @if ($sourceOffer !== null)
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Shop page') }}:</span>
                        <q>{{ $sourceOffer }}</q>
                        @if ($deadline !== null && ! $isUpcoming)
                            · {{ $deadline }}
                        @endif
                    @else
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Offer period') }}:</span>
                        {{ $deadline }}
                    @endif
                </p>
            </div>
        @endif

        @if ($beforeDiscount !== null)
            <p @class([
                'text-base text-zinc-600 tabular-nums sm:text-sm dark:text-zinc-300',
                'border-t border-amber-900/10 pt-2 dark:border-amber-200/10' => $bundle !== null || $window !== null,
            ]) data-test="discount-check">
                {{ __('Shop says it was :claimed. Lowest here in the 30 days before: :lowest.', [
                    'claimed' => \App\Support\MoneyFormatter::format($beforeDiscount->claimedRegularPrice, $beforeDiscount->currency),
                    'lowest' => \App\Support\MoneyFormatter::format($beforeDiscount->lowestBefore, $beforeDiscount->currency),
                ]) }}
            </p>
        @endif
    </div>
@endif
