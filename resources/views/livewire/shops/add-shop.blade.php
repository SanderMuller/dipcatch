<div class="space-y-4">
    {{--
        The suggestions list sits above the URL field: someone who opened
        this disclosure to paste a URL usually wants one of these instead.
        It hides while a shop's page is read and once a preview shows, so it
        cannot invite a second add or compete with the preview.
    --}}
    @if ($state === 'idle' || $state === 'error')
        <div wire:loading.remove.block>
            @livewire('suggestions.shop-suggestions', ['product' => $product, 'expanded' => $expandSuggestions, 'explainEmpty' => true], key('shop-suggestions-add-' . $product->id))
        </div>
    @endif

    @if ($state === 'idle' || $state === 'error')
        {{-- Wrapped: inside a form, Livewire scopes wire:loading to that
             form's own submit, and a suggestion's add is not one. --}}
        <div wire:loading.remove.block>
        <form wire:submit.prevent="probe" class="space-y-3">
            <flux:input
                id="add-shop-url"
                type="url"
                wire:model="url"
                :label="__('Add another shop')"
                :description="__('Paste a product link. We check the price and show you a preview before saving.')"
                placeholder="https://shop.example.com/product/123"
                required
            />
            <flux:button type="submit" variant="primary">Check price</flux:button>
        </form>
        </div>

        {{-- No target: a suggestion's "Add" reaches this component as an
             event, not as the `probe` action, and reading the shop's page
             takes seconds either way. In this state every request is one. --}}
        <div wire:loading.block class="space-y-2" data-test="add-shop-checking">
            <flux:text size="sm" class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300" role="status">
                <flux:icon.loading class="size-4" />
                {{ __('Checking the price on the shop\'s page. This can take a few seconds.') }}
            </flux:text>
            <flux:skeleton animate="pulse" class="h-4 w-2/3" aria-hidden="true" />
            <flux:skeleton animate="pulse" class="h-20 w-full" aria-hidden="true" />
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
            $bundleLabel = $this->previewBundleLabel();
            $hasLivePreviewBundle = $bundleLabel !== null && $this->previewBundleIsLive();
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
        <flux:card class="space-y-3">
            @php($alreadyTracked = $this->alreadyTrackedNote())
            @if ($alreadyTracked !== null)
                <flux:callout icon="exclamation-triangle" color="amber">
                    <flux:callout.text>{{ $alreadyTracked }}</flux:callout.text>
                </flux:callout>
            @endif
            @if ($otherPack = $this->otherPackNote())
                <flux:callout icon="scale" color="amber" data-test="other-pack-warning">
                    <flux:callout.text>{{ $otherPack }}</flux:callout.text>
                </flux:callout>
            @endif
            @if ($this->doubtsSameProduct())
                <flux:callout icon="exclamation-triangle" color="amber" data-test="same-product-warning">
                    <flux:callout.heading>{{ __('This may be a different product or pack') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('AI compared this page with the shops you already track for :product. Check the name and the pack size before you confirm.', ['product' => $product->title]) }}</flux:callout.text>
                </flux:callout>
            @endif
            @php($pageUrl = $this->previewPageUrl())
            {{-- A container query, not a breakpoint: the form sits in a narrow column on a wide screen. --}}
            <div class="@container">
                <div class="grid gap-4 @xl:grid-cols-[minmax(0,1fr)_13rem]" data-test="preview-compare">
                    <div class="flex flex-col gap-4 @sm:flex-row @sm:items-start">
                        <x-preview-page-image :images="$imageUrls" :href="$pageUrl" :host="$host" :name="'preview-photos-' . $this->getId()" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                                <img src="{{ \App\Support\Favicon::url($host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                                {{ $host }}
                            </div>
                            <flux:heading><x-title-diff :title="(string) $snapshot['title']" :other="$product->title" data-test="preview-page-title" /></flux:heading>
                            <x-open-page-link :href="$pageUrl" :host="$host" class="mt-1" data-test="preview-open-page" />
                            {{-- Per unit first when the page states a pack size: the figure
                                 shops are compared on. The pack price follows beneath. --}}
                            <div class="mt-1 text-lg font-semibold tabular-nums" data-test="preview-price">
                                @if ($previewUnitPrice !== null)
                                    {{ $previewUnitPrice }}
                                    @if ($hasLivePreviewBundle && $previewRegularUnitPrice !== null)
                                        <del title="{{ __('Regular price') }}" class="ms-1 text-zinc-400 dark:text-zinc-500">{{ $previewRegularUnitPrice }}</del>
                                    @endif
                                @else
                                    {{ \App\Support\MoneyFormatter::format($snapshot['price'], $snapshot['currency']) }}
                                    @if ($hasLivePreviewBundle && $previewRegularPrice !== null)
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
                            @if ($variantPicked)
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-zinc-500" data-test="picked-variant">
                                    <span>{{ __('This page sells several packs. DipCatch picked the one that matches this product\'s size.') }}</span>
                                    <flux:button variant="ghost" size="xs" wire:click="chooseAnotherVariant">{{ __('Choose another') }}</flux:button>
                                </div>
                            @endif
                            @if ($bundleLabel)
                                <flux:text size="sm" class="mt-1 text-zinc-500">{{ $bundleLabel }}</flux:text>
                            @endif
                            @if ($previewUnitPrice !== null)
                                <flux:text size="sm" class="mt-1 tabular-nums text-zinc-500" data-test="preview-pack">
                                    {{ \App\Support\PackLine::format($snapshot['price'], $snapshot['currency'], $previewPackSize) }}
                                    @if ($hasLivePreviewBundle && $previewRegularPrice !== null)
                                        <del title="{{ __('Regular price') }}" class="ms-1 text-zinc-400 dark:text-zinc-500">{{ \App\Support\MoneyFormatter::format($previewRegularPrice, $snapshot['currency']) }}</del>
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

                    <div class="rounded-xl bg-zinc-50 p-3 ring-1 ring-line dark:bg-white/5" data-test="preview-tracked">
                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('Your product') }}</p>
                        <div class="mt-2 flex items-start gap-3 @xl:flex-col">
                            <x-product-thumb :product="$product" size="size-20 @xl:size-24" />
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white"><x-title-diff :title="$product->title" :other="(string) $snapshot['title']" data-test="preview-product-title" /></p>
                                <ul class="mt-1 space-y-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                    @foreach ($product->shops as $trackedShop)
                                        @php($trackedPack = $trackedShop->packSize())
                                        <li class="truncate">{{ $trackedPack === null ? $trackedShop->host : $trackedShop->host . ' · ' . \App\Support\UnitWord::pack($trackedPack) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @php($barcodeHost = $this->barcodeMatchHost())
            @php($matchPercent = $this->sameProductPercent())
            @if ($barcodeHost !== null || $this->sellsTrackedPack() || $matchPercent !== null)
                <div class="flex flex-wrap gap-2" data-test="preview-signals">
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

            <livewire:ai-feature-prompt feature="shop_checks" wire:key="ai-prompt-add-shop" />

            <div class="flex flex-wrap gap-2">
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
            <div class="mt-2 flex flex-wrap gap-2">
                <flux:button type="button" wire:click="cancel">{{ __('Try a different URL') }}</flux:button>
                @if ($this->canKeepAsLink())
                    {{-- Finding a shop that sells the thing is the slow part, and
                         this is the moment that work would otherwise be thrown
                         away. The link holds no price, so it can never decide
                         either answer. --}}
                    <flux:button type="button" variant="primary" wire:click="keepAsLink">
                        <span wire:loading.remove wire:target="keepAsLink">{{ __('Keep as a link') }}</span>
                        <span wire:loading wire:target="keepAsLink">{{ __('Keeping…') }}</span>
                    </flux:button>
                @endif
            </div>
            @if ($this->canKeepAsLink())
                <flux:text size="sm" class="mt-2 text-zinc-500">
                    {{ __('It is saved without a price, never decides the cheapest or the best value, and is checked again weekly — if the page becomes readable, DipCatch starts tracking it by itself.') }}
                </flux:text>
            @endif
        </flux:callout>
    @endif
</div>
