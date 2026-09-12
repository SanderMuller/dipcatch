<div class="space-y-4">
    {{--
        The suggestions list sits above the URL field: someone who opened
        this disclosure to paste a URL usually wants one of these instead.
        It hides once a probe is in flight so it cannot compete with the
        preview the user has to act on.
    --}}
    @if ($state === 'idle' || $state === 'error')
        @livewire('suggestions.shop-suggestions', ['product' => $product], key('shop-suggestions-add-' . $product->id))
    @endif

    @if ($state === 'idle' || $state === 'error')
        <form wire:submit.prevent="probe" class="space-y-3">
            <flux:input
                id="add-shop-url"
                type="url"
                wire:model="url"
                :label="__('Add another shop')"
                :description="__('Paste a product URL. We will fetch the price and show a preview before saving.')"
                placeholder="https://shop.example.com/product/123"
                required
            />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="probe">Check price</span>
                <span wire:loading wire:target="probe">Checking…</span>
            </flux:button>
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
            if ($previewPackSize !== null && is_string($snapshot['price'] ?? null)) {
                $previewUnitPriceValue = $previewPackSize->unitPriceFor($snapshot['price']);
                if ($previewUnitPriceValue !== null) {
                    $previewUnitPrice = \App\Support\MoneyFormatter::format($previewUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
                }
            }
        @endphp
        <flux:card class="space-y-3">
            <div class="flex items-start gap-3">
                @if (! empty($snapshot['image_url']))
                    <img src="{{ $snapshot['image_url'] }}" alt="" class="h-20 w-20 object-cover rounded" />
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                        <img src="{{ \App\Support\Favicon::url($host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                        {{ $host }}
                    </div>
                    <flux:heading>{{ $snapshot['title'] }}</flux:heading>
                    <div class="mt-1 text-lg font-semibold tabular-nums">
                        {{ \App\Support\MoneyFormatter::format($snapshot['price'], $snapshot['currency']) }}
                        @if (($snapshot['in_stock'] ?? null) === false)
                            <flux:badge color="amber" size="sm" class="ms-2">Out of stock</flux:badge>
                        @elseif (($snapshot['in_stock'] ?? null) === null)
                            <flux:badge color="zinc" size="sm" class="ms-2">Stock unknown</flux:badge>
                        @endif
                    </div>
                    @if ($bundleLabel = \App\Support\BundlePriceLabel::forSnapshot($snapshot))
                        <flux:text size="sm" class="mt-1 text-zinc-500">{{ $bundleLabel }}</flux:text>
                    @endif
                    @if ($previewUnitPrice !== null)
                        <flux:text size="sm" class="mt-1 tabular-nums text-zinc-500">{{ $previewUnitPrice }}</flux:text>
                    @endif
                    @if ($adapterKey === 'user-selector')
                        <flux:text size="sm" class="mt-1 text-zinc-500">Extracted via manual selector.</flux:text>
                    @endif
                    @if ($adapterKey === 'checkjebon')
                        <flux:text size="sm" class="mt-1 text-zinc-500">Daily price via checkjebon.nl. No product image available.</flux:text>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <flux:button type="button" variant="primary" wire:click="confirm">
                    Confirm: same product
                </flux:button>
                <flux:button type="button" wire:click="cancel">
                    Different product
                </flux:button>
            </div>
        </flux:card>
    @endif

    @if ($state === 'error')
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>Could not add this shop</flux:callout.heading>
            <flux:callout.text>
                @switch($errorCode)
                    @case('invalid_url')
                        That doesn't look like a valid URL.
                        @break
                    @case('empty_url')
                        Paste a product URL above.
                        @break
                    @case('duplicate')
                        @php $dupHost = $errorContext['existing_shop_host'] ?? null; @endphp
                        This URL is already tracked for this product{{ $dupHost ? ' (' . $dupHost . ')' : '' }}.
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
                        You've probed too many URLs in the last minute. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('temporary_failure')
                        @if ($errorContext['persistent'] ?? false)
                            This shop hasn't answered our last {{ $errorContext['failures'] ?? 0 }} requests. Trying again now won't help.
                        @else
                            The shop is having a server problem (HTTP {{ $errorContext['status'] ?? '5xx' }}). Try again later.
                        @endif
                        @break
                    @case('http_error')
                        The shop returned HTTP {{ $errorContext['status'] ?? 'error' }}. Check the URL and try again.
                        @break
                    @case('currency_mismatch')
                        That shop sells in {{ $errorContext['actual'] ?? '?' }} but this product is tracked in {{ $errorContext['expected'] ?? '?' }}. Multi-currency tracking is not supported yet.
                        @break
                    @case('extraction_failed')
                        DipCatch could not read a price from that page. Most shops work from the product URL itself.
                        <x-shop-request-link :url="$url" class="font-medium" />
                        @break
                    @case('shop_not_servable')
                        This shop builds its prices in the browser, so there is nothing for DipCatch to read on the page. It cannot be tracked.
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
            <flux:button type="button" class="mt-2" wire:click="cancel">Try a different URL</flux:button>
        </flux:callout>
    @endif
</div>
