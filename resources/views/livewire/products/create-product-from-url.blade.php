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
                <flux:button type="submit" variant="primary">
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
                    $previewUnitPrice = \App\Support\MoneyFormatter::unitPrice($previewUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
                }
                if ($previewRegularPrice !== null) {
                    $previewRegularUnitPriceValue = $previewPackSize->unitPriceFor($previewRegularPrice);
                    if ($previewRegularUnitPriceValue !== null) {
                        $previewRegularUnitPrice = \App\Support\MoneyFormatter::unitPrice($previewRegularUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
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
                    @php($variantNote = is_string($snapshot['variant_note'] ?? null) ? $snapshot['variant_note'] : null)
                    @if ($variantNote !== null)
                        {{-- Which of the page's variants this price belongs to. A
                             page selling three flavours used to preview one price
                             with nothing saying the other two existed. --}}
                        <flux:text size="sm" class="mt-1 text-zinc-500">{{ $variantNote }}</flux:text>
                    @endif
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

                @php($suggested = $this->suggestedThresholds())
                @php($targetPacks = $this->unitTargetPacks())

                @if ($targetPacks !== [])
                    {{-- The per-unit target leads: it holds every shop and pack
                         size added later to the same rate. Optional. --}}
                    @php($targetUnitWord = \App\Support\UnitWord::noun($this->snapshotPackSize()?->unit) ?? __('unit'))
                    <x-unit-target
                        class="pt-2"
                        model="unitPriceTarget"
                        :description="auth()->user()?->entitlements()->allowsUnitPriceAlerts()
                            ? __('Optional.')
                            : __('Pro alerts on this. We keep the number, and it starts working when you upgrade.')"
                        :upgrade="! auth()->user()?->entitlements()->allowsUnitPriceAlerts()"
                        :packs="$targetPacks"
                        :currency="$snapshot['currency'] ?? 'EUR'"
                        :unit-word="$targetUnitWord"
                    />
                @endif

                <details class="group" wire:ignore.self @if ($targetPacks === []) open @endif>
                    <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-zinc-700 select-none dark:text-zinc-300 [&::-webkit-details-marker]:hidden">
                        <flux:icon.chevron-right variant="micro" class="transition group-open:rotate-90" />
                        {{ __('Other alerts: a drop in percent or money') }}
                    </summary>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <flux:input
                            id="create-product-threshold-pct"
                            type="number"
                            step="0.01"
                            min="0.01"
                            max="99.99"
                            wire:model="thresholdPct"
                            :placeholder="$suggested['pct']"
                            :label="__('Alert me when it drops by (%)')"
                            class="tabular-nums"
                        />
                        <flux:input
                            id="create-product-threshold-abs"
                            type="number"
                            step="0.01"
                            min="0.01"
                            wire:model="thresholdAbs"
                            :placeholder="$suggested['abs']"
                            :label="'Alert me when it drops by ('.$snapshot['currency'].')'"
                            class="tabular-nums"
                        />
                    </div>
                    <flux:text size="sm" class="mt-2 text-zinc-500">Optional. Leave them empty and we use the suggestion shown, worked out from the price at the time. You hear from us as soon as the price drops past either one.</flux:text>
                </details>

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">
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
                @include('livewire.shops.partials.probe-error')
            </flux:callout.text>
            <flux:text class="mt-2">
                Can't reach the page? You can <flux:link :href="route('app.products.create-manual')">fill the product in yourself</flux:link>.
            </flux:text>
        </flux:callout>
    @endif
</div>
