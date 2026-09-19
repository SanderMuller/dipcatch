<div class="max-w-2xl space-y-4">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Track a product') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    @if ($state === 'idle' || $state === 'error')
        <form wire:submit.prevent="probe" class="space-y-3">
            <flux:input
                id="create-product-url"
                type="url"
                wire:model="url"
                :label="__('Product link')"
                :description="__('Paste a product link. We pick up the name, the photo and the price, and suggest when to alert you.')"
                placeholder="https://shop.example.com/product/123"
                required
                autofocus
            />
            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="probe">Look up this product</span>
                    <span wire:loading wire:target="probe">Looking it up…</span>
                </flux:button>
                <flux:link :href="route('app.products.create-manual')">
                    Fill it in myself
                </flux:link>
            </div>
        </form>

        <div wire:loading wire:target="probe" class="space-y-2" aria-hidden="true">
            <flux:skeleton animate="pulse" class="h-4 w-2/3" />
            <flux:skeleton animate="pulse" class="h-20 w-full" />
        </div>
    @endif

    @if ($state === 'manual_selector')
        @include('livewire.shops.partials.manual-selector')
    @endif

    @if ($state === 'variant_chooser' && $variants !== null)
        @include('livewire.shops.partials.variant-chooser')
    @endif

    @if ($state === 'preview' && $snapshot !== null)
        @php
            $previewPackSize = $this->snapshotPackSize();
            $previewUnitPrice = null;
            $previewRegularPrice = is_string($snapshot['single_item_price'] ?? null) ? $snapshot['single_item_price'] : null;
            $previewRegularUnitPrice = null;
            $bundleLabel = \App\Support\BundlePriceLabel::forSnapshot($snapshot);
            $hasLivePreviewBundle = $bundleLabel !== null
                && \App\Support\BundlePriceLabel::snapshotPriceIsBundleUnit($snapshot);
            if ($previewPackSize !== null && is_string($snapshot['price'] ?? null)) {
                $previewUnitPriceValue = $previewPackSize->unitPriceFor($snapshot['price']);
                if ($previewUnitPriceValue !== null) {
                    $previewUnitPrice = \App\Support\MoneyFormatter::format($previewUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
                }
                if ($previewRegularPrice !== null) {
                    $previewRegularUnitPriceValue = $previewPackSize->unitPriceFor($previewRegularPrice);
                    if ($previewRegularUnitPriceValue !== null) {
                        $previewRegularUnitPrice = \App\Support\MoneyFormatter::format($previewRegularUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
                    }
                }
            }
        @endphp
        <flux:card class="space-y-4">
            @if ($existingTrackedProduct !== null)
                <flux:callout variant="warning">
                    You already follow this link on
                    <flux:link :href="route('app.products.show', $existingTrackedProduct['id'])">{{ $existingTrackedProduct['title'] }}</flux:link>.
                    You can still create a separate product for it.
                </flux:callout>
            @endif

            <div class="flex items-start gap-3">
                @if (! empty($snapshot['image_url']))
                    <img src="{{ $snapshot['image_url'] }}" alt="" class="h-20 w-20 rounded object-cover" />
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                        <img src="{{ \App\Support\Favicon::url($host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                        {{ $host }}
                    </div>
                    <div class="mt-1 text-lg font-semibold tabular-nums">
                        {{ \App\Support\MoneyFormatter::format($snapshot['price'], $snapshot['currency']) }}
                        @if ($hasLivePreviewBundle)
                            @if ($previewRegularPrice !== null)
                                <del title="{{ __('Regular price') }}" class="ms-1 text-zinc-400 dark:text-zinc-500">{{ \App\Support\MoneyFormatter::format($previewRegularPrice, $snapshot['currency']) }}</del>
                            @endif
                        @endif
                        @if (($snapshot['in_stock'] ?? null) === false)
                            <flux:badge color="amber" size="sm" class="ms-2">Out of stock</flux:badge>
                        @elseif (($snapshot['in_stock'] ?? null) === null)
                            <flux:badge color="zinc" size="sm" class="ms-2">Stock unknown</flux:badge>
                        @endif
                    </div>
                    @if ($bundleLabel)
                        <flux:text size="sm" class="mt-1 text-zinc-500">{{ $bundleLabel }}</flux:text>
                    @endif
                    @if ($previewUnitPrice !== null)
                        <flux:text size="sm" class="mt-1 tabular-nums text-zinc-500">
                            {{ $previewUnitPrice }}
                            @if ($hasLivePreviewBundle && $previewRegularUnitPrice !== null)
                                <del title="{{ __('Regular price') }}" class="ms-1 text-zinc-400 dark:text-zinc-500">{{ $previewRegularUnitPrice }}</del>
                            @endif
                        </flux:text>
                    @endif
                    @if ($adapterKey === 'user-selector')
                        <flux:text size="sm" class="mt-1 text-zinc-500">Extracted via manual selector.</flux:text>
                    @endif
                    @if ($adapterKey === 'checkjebon')
                        <flux:text size="sm" class="mt-1 text-zinc-500">Daily price via checkjebon.nl. No product image available.</flux:text>
                    @endif
                </div>
            </div>

            <form wire:submit.prevent="confirm" class="space-y-3">
                <flux:input id="create-product-title" wire:model="title" :label="__('Title')" required />
                <flux:input id="create-product-image-url" type="url" wire:model="imageUrl" :label="__('Image URL')" />

                <div class="grid grid-cols-2 gap-3">
                    <flux:input
                        id="create-product-threshold-pct"
                        type="number"
                        step="0.01"
                        min="0.01"
                        max="99.99"
                        wire:model="thresholdPct"
                        :label="__('Alert me when it drops by (%)')"
                        class="tabular-nums"
                    />
                    <flux:input
                        id="create-product-threshold-abs"
                        type="number"
                        step="0.01"
                        min="0.01"
                        wire:model="thresholdAbs"
                        :label="'Alert me when it drops by ('.$snapshot['currency'].')'"
                        class="tabular-nums"
                    />
                </div>
                <flux:text size="sm" class="text-zinc-500">We suggest these from the price. You hear from us as soon as the price drops past either one.</flux:text>

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="confirm">Create product</span>
                        <span wire:loading wire:target="confirm">Creating…</span>
                    </flux:button>
                    <flux:button type="button" wire:click="cancel">Start over</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    @if ($state === 'error')
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>We could not open that page</flux:callout.heading>
            <flux:callout.text>
                @switch($errorCode)
                    @case('invalid_url')
                        That does not look like a product link.
                        @break
                    @case('empty_url')
                        Paste a product link above.
                        @break
                    @case('robots_disallowed')
                        This shop's robots.txt forbids automated access.
                        @break
                    @case('blocked')
                        @if ($errorContext['persistent'] ?? false)
                            This shop has blocked our last {{ $errorContext['failures'] ?? 0 }} requests. Trying again won't help.
                        @else
                            This shop is blocking automated checks (Cloudflare/Akamai). We can't track it right now.
                        @endif
                        @break
                    @case('host_rate_limited')
                        This shop returned a rate-limit response (HTTP 429). Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('local_throttle')
                        We're spacing out checks to this shop to be polite. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('probe_rate_limited')
                        You have checked too many links in the last minute. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('temporary_failure')
                        @if ($errorContext['persistent'] ?? false)
                            This shop hasn't answered our last {{ $errorContext['failures'] ?? 0 }} requests. Trying again now won't help.
                        @else
                            The shop is having a server problem (HTTP {{ $errorContext['status'] ?? '5xx' }}). Try again later.
                        @endif
                        @break
                    @case('http_error')
                        The shop answered with an error. Check the link and try again.
                        @break
                    @case('extraction_failed')
                        DipCatch could not read a price from that page. Most shops work from the product URL itself.
                        <x-shop-request-link :url="$url" class="font-medium" />
                        @break
                    @case('not_in_dataset')
                        @php $njReason = $errorContext['reason'] ?? null; @endphp
                        @if ($njReason === 'unrecognized_url')
                            No product id found in that URL. Paste a product page URL for this shop (not a category or search page).
                        @elseif ($njReason === 'dataset_empty')
                            The daily price dataset has not been loaded yet. Run <code>php artisan dipcatch:refresh-checkjebon</code> once.
                        @else
                            This product is not in the daily price dataset (checkjebon.nl). DipCatch can only track dataset-listed products for this shop.
                        @endif
                        @break
                    @default
                        {{ $errorCode }}
                @endswitch
            </flux:callout.text>
            <flux:text class="mt-2">
                Can't reach the page? You can <flux:link :href="route('app.products.create-manual')">fill the product in yourself</flux:link>.
            </flux:text>
        </flux:callout>
    @endif
</div>
