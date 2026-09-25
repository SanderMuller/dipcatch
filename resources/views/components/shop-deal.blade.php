@props([
    'shop',
    'showSource' => true,
])

@php
    $liveBundle = $shop->liveBundleOffer();
    $singleItemPrice = $shop->singleItemPrice();
    $window = $shop->promotionWindow();
    $isUpcoming = $window?->hasNotStarted() ?? false;
    $bundle = $isUpcoming ? $shop->bundleOffer() : $liveBundle;
    $sourceOffer = $window?->label;
    $deadline = $window === null ? null : \App\Support\PromotionLabel::short($shop);
    $surfaceClasses = $isUpcoming
        ? 'bg-blue-50/70 ring-blue-900/10 dark:bg-blue-950/25 dark:ring-blue-200/10'
        : 'bg-amber-50/70 ring-amber-900/10 dark:bg-amber-950/25 dark:ring-amber-200/10';
@endphp

@if ($bundle !== null || $window !== null)
    <div {{ $attributes->class("space-y-2 rounded-lg p-3 ring-1 {$surfaceClasses}") }}>
        @if ($isUpcoming)
            {{-- Announced, not running: say what it gives, at which shop and
                 when, so it cannot be read as today's price. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-blue-700 dark:text-blue-300">
                <flux:icon.calendar-days class="size-4" />
                <flux:badge size="sm" color="blue">{{ __('Upcoming deal') }}</flux:badge>
            </div>
            <p class="text-base font-medium text-zinc-900 sm:text-sm dark:text-zinc-100" data-test="upcoming-deal-terms">
                {{ \App\Support\PromotionLabel::upcomingTerms($shop) }}
                <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ __('at :host', ['host' => $shop->host]) }}</span>
            </p>
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
    </div>
@endif
