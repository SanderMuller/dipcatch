@props([
    'shop',
    'showSource' => true,
])

@php
    $bundle = $shop->liveBundleOffer();
    $singleItemPrice = $shop->singleItemPrice();
    $window = $shop->promotionWindow();
    $sourceOffer = $window?->label;
    $deadline = $window === null ? null : \App\Support\PromotionLabel::short($shop);
@endphp

@if ($bundle !== null || ($showSource && $window !== null))
    <div {{ $attributes->class('space-y-2 rounded-lg bg-amber-50/70 p-3 ring-1 ring-amber-900/10 dark:bg-amber-950/25 dark:ring-amber-200/10') }}>
        @if ($bundle !== null)
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <flux:badge size="sm" color="amber">{{ __('Deal') }}</flux:badge>
                <p class="text-base font-medium tabular-nums text-zinc-900 sm:text-sm dark:text-zinc-100">
                    {{ \App\Support\BundlePriceLabel::condition($bundle, $shop->currency) }}
                </p>
                @if ($singleItemPrice !== null)
                    <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                        {{ __('Normal price: :price each', ['price' => \App\Support\MoneyFormatter::format($singleItemPrice, $shop->currency)]) }}
                    </p>
                @endif
                @if (! $showSource && $deadline !== null)
                    <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ $deadline }}</p>
                @endif
            </div>
        @endif

        @if ($showSource && $window !== null)
            <div @class([
                'border-t border-amber-900/10 pt-2 dark:border-amber-200/10' => $bundle !== null,
            ])>
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                    @if ($sourceOffer !== null)
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Shop page') }}:</span>
                        <q>{{ $sourceOffer }}</q>
                        @if ($deadline !== null)
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
