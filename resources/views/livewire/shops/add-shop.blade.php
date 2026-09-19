<div class="space-y-4">
    {{--
        The suggestions list sits above the URL field: someone who opened
        this disclosure to paste a URL usually wants one of these instead.
        It hides once a probe is in flight so it cannot compete with the
        preview the user has to act on.
    --}}
    @if ($state === 'idle' || $state === 'error')
        @livewire('suggestions.shop-suggestions', ['product' => $product, 'expanded' => $expandSuggestions], key('shop-suggestions-add-' . $product->id))
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
                @include('livewire.shops.partials.probe-error')
            </flux:callout.text>
            <flux:button type="button" class="mt-2" wire:click="cancel">Try a different URL</flux:button>
        </flux:callout>
    @endif
</div>
