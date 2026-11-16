{{--
    The add-shop form in the dashboard's review dialog: the product on the
    left, the shop's page on the right, so the two can be compared before
    the shop is added. The page is read once the dialog has drawn, so the
    dialog opens at once and the shop's column shows its loading state.
--}}
@php
    $shopName = $review['shop'];
    $reviewHost = $host ?? (string) parse_url($review['url'], PHP_URL_HOST);
    $pageTitle = $state === 'preview' && $snapshot !== null ? (string) $snapshot['title'] : null;
    $trackedPacks = $product->shops
        ->map(fn (\App\Models\Shop $shop): ?\App\Support\PackSize => $shop->packSize())
        ->filter()
        ->map(fn (\App\Support\PackSize $size): string => \App\Support\UnitWord::pack($size))
        ->unique()
        ->values();
    $cheapest = $product->cheapestShop;
@endphp
<div wire:init="startReview" class="space-y-5" data-test="review-shop">
    <div class="grid gap-4 md:grid-cols-2">
        {{-- The product the shop would be added to. --}}
        <section class="min-w-0 rounded-xl bg-zinc-50 p-4 dark:bg-white/5" aria-labelledby="review-product-{{ $this->getId() }}" data-test="review-product">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Your product') }}</p>
            <div class="mt-3 flex min-w-0 items-start gap-4">
                <x-product-thumb :product="$product" size="size-24" />
                <div class="min-w-0 flex-1">
                    <h3 id="review-product-{{ $this->getId() }}" class="text-base font-medium text-zinc-900 sm:text-sm dark:text-white" data-test="review-product-title">
                        @if ($pageTitle !== null)
                            <x-title-diff :title="$product->title" :other="$pageTitle" />
                        @else
                            {{ $product->title }}
                        @endif
                    </h3>
                    <dl class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-base sm:text-sm">
                        <dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Size') }}</dt>
                        <dd class="text-zinc-500 dark:text-zinc-400" data-test="review-product-size">{{ $trackedPacks->isEmpty() ? __('Not stated') : $trackedPacks->implode(', ') }}</dd>
                        @if ($cheapest?->current_price !== null)
                            <dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Now') }}</dt>
                            <dd class="truncate text-zinc-500 tabular-nums dark:text-zinc-400">{{ __(':price at :shop', ['price' => \App\Support\MoneyFormatter::format($cheapest->current_price, $product->currency), 'shop' => $cheapest->displayName()]) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <p class="mt-4 text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ trans_choice('Tracked at :count shop|Tracked at :count shops', $product->shops->count(), ['count' => $product->shops->count()]) }}</p>
            <ul role="list" class="mt-2 space-y-1.5">
                @foreach ($product->shops as $trackedShop)
                    @php $trackedPack = $trackedShop->packSize(); @endphp
                    <li class="flex min-w-0 items-center gap-2 text-base sm:text-sm">
                        <x-shop-name :host="$trackedShop->host" :name="$trackedShop->displayName()" class="text-zinc-700 dark:text-zinc-300" />
                        @if ($trackedPack !== null)
                            <span class="shrink-0 text-zinc-500 dark:text-zinc-400">{{ \App\Support\UnitWord::pack($trackedPack) }}</span>
                        @endif
                        @if ($trackedShop->current_price !== null)
                            <span class="ms-auto shrink-0 text-zinc-500 tabular-nums dark:text-zinc-400">{{ \App\Support\MoneyFormatter::format($trackedShop->current_price, $product->currency) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- The shop being reviewed, as its page reads now. --}}
        <section class="min-w-0 rounded-xl p-4 ring-1 ring-zinc-950/10 dark:ring-white/10" aria-labelledby="review-page-{{ $this->getId() }}" data-test="review-page">
            <p class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-zinc-500 dark:text-zinc-400">
                <span class="shrink-0">{{ __('Shop to add') }}</span>
                <span aria-hidden="true">·</span>
                <x-shop-name :host="$reviewHost" :name="$shopName" class="text-zinc-900 dark:text-white" />
            </p>

            @if ($state === 'idle')
                <div class="mt-3 space-y-3" data-test="review-page-loading">
                    <p class="flex items-center gap-2 text-base text-zinc-600 sm:text-sm dark:text-zinc-300" role="status">
                        <flux:icon.loading variant="micro" class="size-4 shrink-0" />
                        {{ __('Reading the product page on :shop. This takes a few seconds.', ['shop' => $shopName]) }}
                    </p>
                    <div class="flex items-start gap-4" aria-hidden="true">
                        <flux:skeleton animate="pulse" class="size-24 shrink-0 rounded-xl" />
                        <div class="flex-1 space-y-2">
                            <flux:skeleton animate="pulse" class="h-4 w-11/12" />
                            <flux:skeleton animate="pulse" class="h-4 w-2/3" />
                            <flux:skeleton animate="pulse" class="h-6 w-1/3" />
                        </div>
                    </div>
                </div>
            @endif

            @if ($state === 'manual_selector')
                <div class="mt-3">@include('livewire.shops.partials.manual-selector')</div>
            @endif

            @if ($state === 'variant_chooser' && $variants !== null)
                <div class="mt-3">@include('livewire.shops.partials.variant-chooser')</div>
            @endif

            @if ($state === 'error')
                <flux:callout variant="danger" icon="exclamation-triangle" class="mt-3" data-test="review-page-error">
                    <flux:callout.heading>{{ __('DipCatch could not read this page') }}</flux:callout.heading>
                    <flux:callout.text>@include('livewire.shops.partials.probe-error')</flux:callout.text>
                </flux:callout>
                @if ($this->canKeepAsLink())
                    <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400">
                        {{ __('You can keep it as a link. It is saved without a price, never decides the cheapest or the best value, and is checked again weekly.') }}
                    </flux:text>
                @endif
            @endif

            @if ($state === 'preview' && $snapshot !== null)
                @php
                    $previewPackSize = $this->snapshotPackSize();
                    $previewUnitPriceValue = $previewPackSize?->unitPriceFor($snapshot['price']);
                    $pageUrl = $this->previewPageUrl();
                    $variantNote = is_string($snapshot['variant_note'] ?? null) ? $snapshot['variant_note'] : null;
                    $barcodeHost = $this->barcodeMatchHost();
                    $matchPercent = $this->sameProductPercent();
                @endphp
                <div class="mt-3 flex min-w-0 items-start gap-4">
                    <x-preview-page-image :images="$imageUrls" :href="$pageUrl" :host="$host" :name="'review-photos-' . $this->getId()" class="size-24! sm:size-24!" />
                    <div class="min-w-0 flex-1">
                        <h3 id="review-page-{{ $this->getId() }}" class="text-base font-medium text-zinc-900 sm:text-sm dark:text-white" data-test="review-page-title">
                            <x-title-diff :title="$pageTitle" :other="$product->title" />
                        </h3>
                        <dl class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-base sm:text-sm">
                            <dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Size') }}</dt>
                            <dd @class(['text-zinc-500 dark:text-zinc-400', 'font-medium text-amber-700! dark:text-amber-400!' => $previewPackSize !== null && $this->otherPackNote() !== null]) data-test="review-page-size">{{ $previewPackSize === null ? __('Not stated') : \App\Support\UnitWord::pack($previewPackSize) }}</dd>
                            <dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Price') }}</dt>
                            <dd class="text-zinc-500 tabular-nums dark:text-zinc-400" data-test="review-page-price">
                                {{ \App\Support\MoneyFormatter::format($snapshot['price'], $snapshot['currency']) }}@if ($previewUnitPriceValue !== null) · {{ \App\Support\MoneyFormatter::unitPrice($previewUnitPriceValue, $snapshot['currency']) }} {{ $previewPackSize->label() }}@endif
                            </dd>
                            <dt class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Stock') }}</dt>
                            <dd class="text-zinc-500 dark:text-zinc-400">
                                {{ match ($snapshot['in_stock'] ?? null) {
                                    true => __('In stock'),
                                    false => __('Out of stock'),
                                    default => __('Unknown'),
                                } }}
                            </dd>
                        </dl>
                        @if ($variantNote !== null)
                            <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400">{{ $variantNote }}</flux:text>
                        @endif
                        @if ($variantPicked)
                            <div class="mt-2 flex flex-wrap items-center gap-x-2 text-sm text-zinc-500 dark:text-zinc-400" data-test="picked-variant">
                                <span>{{ __('This page sells several packs. DipCatch picked the one that matches this product’s size.') }}</span>
                                <flux:button variant="ghost" size="xs" wire:click="chooseAnotherVariant">{{ __('Choose another') }}</flux:button>
                            </div>
                        @endif
                        <x-open-page-link :href="$pageUrl" :host="$host" class="mt-2" data-test="review-open-page" />
                    </div>
                </div>

                @if ($barcodeHost !== null || $this->sellsTrackedPack() || $matchPercent !== null)
                    <div class="mt-4 flex flex-wrap gap-2" data-test="preview-signals">
                        @if ($barcodeHost !== null)
                            <flux:badge size="sm" color="green" icon="check" data-test="signal-barcode">{{ __('Same barcode as :shop', ['shop' => $barcodeHost]) }}</flux:badge>
                        @endif
                        @if ($this->sellsTrackedPack())
                            <flux:badge size="sm" color="green" icon="check" data-test="signal-pack">{{ __('Same pack as your other shops') }}</flux:badge>
                        @endif
                        @if ($matchPercent !== null)
                            <flux:badge size="sm" color="green" icon="sparkles" data-test="signal-ai">{{ __('AI check: :percent% match', ['percent' => $matchPercent]) }}</flux:badge>
                        @endif
                    </div>
                @endif
            @endif
        </section>
    </div>

    @if ($state === 'preview' && $snapshot !== null)
        @php $alreadyTracked = $this->alreadyTrackedNote(); @endphp
        @php $otherPack = $this->otherPackNote(); @endphp
        @if ($alreadyTracked !== null || $otherPack !== null || $this->doubtsSameProduct())
            <div class="space-y-3">
                @if ($this->doubtsSameProduct())
                    <flux:callout icon="exclamation-triangle" color="amber" data-test="same-product-warning">
                        <flux:callout.heading>{{ __('This may be a different product or pack') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('AI compared this page with the shops you already track. Check the name and the size before you add it.') }}</flux:callout.text>
                    </flux:callout>
                @endif
                @if ($otherPack !== null)
                    <flux:callout icon="scale" color="amber" data-test="other-pack-warning">
                        <flux:callout.text>{{ $otherPack }}</flux:callout.text>
                    </flux:callout>
                @endif
                @if ($alreadyTracked !== null)
                    <flux:callout icon="exclamation-triangle" color="amber">
                        <flux:callout.text>{{ $alreadyTracked }}</flux:callout.text>
                    </flux:callout>
                @endif
            </div>
        @endif
    @endif

    {{-- One primary action: add the shop, or keep a page DipCatch cannot read
         as a link. "Different product" hides the suggestion for this product;
         Skip leaves it for later. The bar sticks to the bottom of the dialog,
         so a long comparison on a phone does not push the buttons out of view.
         Flux shows a spinner on the pressed button; the bar takes no second
         press while an action runs. --}}
    <div
        class="sticky -bottom-6 -mx-6 -mb-6 grid grid-cols-2 gap-2 border-t border-zinc-950/5 bg-white px-6 pt-4 pb-6 sm:flex sm:items-center sm:gap-3 dark:border-white/10 dark:bg-zinc-800"
        wire:loading.class="pointer-events-none"
        wire:target="skip,decline,confirm,keepAsLink"
        data-test="review-actions"
    >
        @if ($state === 'error' && $this->canKeepAsLink())
            <flux:button variant="primary" wire:click="keepAsLink" class="col-span-2 sm:order-last" data-test="review-keep-link">{{ __('Keep as a link') }}</flux:button>
        @else
            <flux:button variant="primary" icon="plus" wire:click="confirm" :disabled="$state !== 'preview'" class="col-span-2 sm:order-last" data-test="review-add">
                {{ __('Add :shop', ['shop' => $shopName]) }}
            </flux:button>
        @endif
        <flux:button variant="ghost" wire:click="skip" class="sm:order-first sm:me-auto" data-test="review-skip">{{ __('Skip for now') }}</flux:button>
        <flux:button wire:click="decline" data-test="review-decline">{{ __('Different product') }}</flux:button>
    </div>
</div>
    </div>
</div>
