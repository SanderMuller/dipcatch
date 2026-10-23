<div @class(['max-w-2xl space-y-4', 'lg:max-w-5xl' => $step === 3 && $product !== null])>
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Track a product') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <flux:heading size="xl" level="1">{{ __('Track a product') }}</flux:heading>

    <ol role="list" aria-label="{{ __('Steps') }}" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" data-test="wizard-steps">
        @foreach ([1 => __('Product'), 2 => __('Shops'), 3 => __('Alerts')] as $number => $label)
            <li class="flex items-center gap-2">
                @if (! $loop->first)
                    <flux:icon.chevron-right variant="micro" class="text-zinc-400" />
                @endif
                @if ($product !== null && $number !== $step)
                    <button type="button" wire:click="goToStep({{ $number }})" class="font-medium text-zinc-500 underline-offset-4 hover:text-zinc-900 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-zinc-400 dark:hover:text-white">
                        {{ $number }}. {{ $label }}
                    </button>
                @else
                    <span @class(['font-semibold text-zinc-900 dark:text-white' => $number === $step, 'text-zinc-500 dark:text-zinc-400' => $number !== $step]) @if ($number === $step) aria-current="step" @endif>
                        {{ $number }}. {{ $label }}
                    </span>
                @endif
            </li>
        @endforeach
    </ol>

    {{-- For a screen reader, and as the focus target between steps: the
         step list above already shows the step on screen. --}}
    <flux:heading level="2" size="lg" id="wizard-step-heading" tabindex="-1" class="sr-only">
        {{ __('Step :number of 3: :label', ['number' => $step, 'label' => [1 => __('Product'), 2 => __('Shops'), 3 => __('Alerts')][$step] ?? '']) }}
    </flux:heading>

    {{-- Always in the page, so a screen reader announces what lands in them. --}}
    <div role="status" class="sr-only" data-test="wizard-status">{{ $status }}</div>
    <div role="alert" aria-atomic="true">
        @if ($limitMessage)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="product-limit">
                <flux:callout.heading>{{ __('You have used all your free products') }}</flux:callout.heading>
                <flux:callout.text>{{ $limitMessage }}</flux:callout.text>
            </flux:callout>
        @endif
    </div>

    @if ($step === 1 && $product === null && $mode === 'manual')
        <form wire:submit="saveManual" class="space-y-4" data-test="manual-product-form">
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Use this when we could not read the shop page. You add the shop links in the next step.') }}</flux:text>
            <flux:input id="wizard-manual-title" wire:model="manualTitle" :label="__('Title')" required />
            <flux:input wire:model="manualImageUrl" :label="__('Image URL')" type="url" />
            <flux:input wire:model="currency" :label="__('Currency')" :description="__('Three-letter code, for example EUR.')" maxlength="3" required />

            <div class="flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Next') }}</flux:button>
                <flux:button type="button" variant="ghost" wire:click="switchMode('url')">{{ __('Paste a shop link instead') }}</flux:button>
            </div>
        </form>
    @elseif ($step === 1 && $product === null)
        @if ($trackingIdea !== null && ($state === 'idle' || $state === 'error'))
            <flux:callout icon="light-bulb" color="zinc" data-test="tracking-idea-hint">
                <flux:callout.heading>{{ $trackingIdea->label() }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Type the product’s name below, or find it at any shop and paste its link. DipCatch reads these shops well:') }}
                </flux:callout.text>
                <ul role="list" class="mt-2 flex flex-wrap gap-2">
                    @foreach ($trackingIdea->group()->shops() as $host)
                        <li class="text-base/7 sm:text-sm/6">
                            <x-host-tooltip :host="$host"><a href="https://www.{{ $host }}" target="_blank" rel="noopener noreferrer" class="flex items-center rounded-full bg-paper py-0.5 pr-3 pl-2 font-medium ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"><x-shop-name :host="$host" /></a></x-host-tooltip>
                        </li>
                    @endforeach
                </ul>
            </flux:callout>
        @endif

        @if ($state === 'idle' || $state === 'error')
            <form wire:submit.prevent="lookUp" class="space-y-3">
                <flux:input
                    id="create-product-url"
                    type="text"
                    wire:model="query"
                    :label="__('Product link or name')"
                    :description="__('Paste a product link, or type the name to search the supermarkets and bol.com. We pick up the name, the photo and the price, and suggest when to alert you.')"
                    :placeholder="__('https://… or Calvé pindakaas 650 g')"
                    autocomplete="off"
                    required
                    autofocus
                />
                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary">
                        <span wire:loading.remove wire:target="lookUp">{{ __('Look up this product') }}</span>
                        <span wire:loading wire:target="lookUp">{{ __('Looking it up…') }}</span>
                    </flux:button>
                    <flux:button type="button" variant="ghost" wire:click="switchMode('manual')">
                        {{ __('Add it by hand instead') }}
                    </flux:button>
                </div>
            </form>

            <div wire:loading wire:target="lookUp, pickResult" class="space-y-2" aria-hidden="true">
                <flux:skeleton animate="pulse" class="h-4 w-2/3" />
                <flux:skeleton animate="pulse" class="h-20 w-full" />
            </div>

            @if ($state === 'error')
                <flux:callout variant="danger" icon="exclamation-triangle" id="wizard-probe-error" tabindex="-1" class="focus:outline-none">
                    <flux:callout.heading>We could not open that page</flux:callout.heading>
                    <flux:callout.text>
                        @include('livewire.shops.partials.probe-error')
                    </flux:callout.text>
                    <flux:text class="mt-2">
                        Can't reach the page? You can <flux:link as="button" wire:click="switchMode('manual')">fill the product in yourself</flux:link>.
                    </flux:text>
                </flux:callout>
            @endif

            @if ($nameResults !== null)
                @include('livewire.products.partials.name-search-results')
            @else
                {{-- Another way in, for someone adding many products: the assistant
                     runs the same lookup and shows what it found before it saves. --}}
                <flux:callout icon="sparkles" color="zinc" class="mt-6" data-test="assistant-hint">
                    <flux:callout.heading>{{ __('Add products and shops from :assistant', ['assistant' => $connectedAssistant ?? 'Claude']) }}</flux:callout.heading>
                    @if ($connectedAssistant !== null)
                        <flux:callout.text>
                            {{ __(':assistant is connected. Paste a product link and ask it to track it, or ask it to add a shop to a product you follow. It shows the name and the price it found before it saves anything.', ['assistant' => $connectedAssistant]) }}
                        </flux:callout.text>
                    @else
                        <flux:callout.text>
                            {{ __('Connect Claude once, then paste a product link and ask it to track it, or ask it to add a shop to a product you follow. It shows the name and the price it found before it saves anything.') }}
                        </flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('app.connections')" wire:navigate>{{ __('Connect Claude') }}</flux:button>
                        </x-slot>
                    @endif
                </flux:callout>
            @endif
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
            <flux:card class="space-y-4">
                @if ($existingTrackedProduct !== null)
                    <flux:callout variant="warning">
                        You already follow this link on
                        <flux:link :href="route('app.products.show', $existingTrackedProduct['id'])">{{ $existingTrackedProduct['title'] }}</flux:link>.
                        You can still create a separate product for it.
                    </flux:callout>
                @endif

                @php($pageUrl = $this->previewPageUrl())
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <x-preview-page-image :images="$imageUrls" :href="$pageUrl" :host="$host" :name="'preview-photos-' . $this->getId()" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                            <x-shop-name :host="$host" />
                        </div>
                        @if (is_string($snapshot['title'] ?? null) && $snapshot['title'] !== '')
                            <flux:heading data-test="preview-title">{{ $snapshot['title'] }}</flux:heading>
                        @endif
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

                <form wire:submit.prevent="confirm" class="space-y-3">
                    <flux:input id="create-product-title" wire:model="title" :label="__('Title')" required />
                    <flux:input id="create-product-image-url" type="url" wire:model="imageUrl" :label="__('Image URL')" />

                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">
                            <span wire:loading.remove wire:target="confirm">{{ __('Next') }}</span>
                            <span wire:loading wire:target="confirm">{{ __('Saving…') }}</span>
                        </flux:button>
                        <flux:button type="button" wire:click="cancel">Start over</flux:button>
                    </div>
                </form>
            </flux:card>
        @endif

    @elseif ($step === 1)
        <form wire:submit="saveDetails" class="space-y-4" data-test="product-details-form">
            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:input wire:model="imageUrl" :label="__('Image URL')" type="url" placeholder="https://…" />
            <x-shop-image-picker :images="$shopImages" />
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                {{ __('To track a different first shop, remove it on the product page later.') }}
            </flux:text>
            <flux:button type="submit" variant="primary">{{ __('Next') }}</flux:button>
        </form>
    @elseif ($step === 2)
        <div class="space-y-4" data-test="wizard-shops">
            @if ($product->shops->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="wizard-no-shops">
                    {{ $searchesWeb ? __('Add one shop and we search the web for more.') : __('Add the shops you buy it at, to compare their prices.') }}
                </flux:text>
            @else
                <ul role="list" class="divide-y divide-zinc-200 rounded-xl ring-1 ring-zinc-200 dark:divide-white/10 dark:ring-white/10" data-test="wizard-shop-list">
                    @foreach ($product->shops->sortBy('created_at') as $shop)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                            <span class="flex min-w-0 items-center gap-2">
                                <x-shop-name :host="$shop->host" />
                            </span>
                            @if ($shop->current_price !== null)
                                <span class="tabular-nums text-zinc-600 dark:text-zinc-300">{{ \App\Support\MoneyFormatter::format((string) $shop->current_price, $product->currency) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- Checked here too, so a step without the prompt keeps no empty gap. --}}
            @if ($couldSearchWeb && \App\Enums\AiFeature::ShopChecks->isOfferedTo(auth()->user(), \App\Enums\AiPromptPlace::WizardShops))
                <livewire:ai-feature-prompt place="wizard_shops" :key="'ai-prompt-wizard-shops-' . $product->id" />
            @endif

            @if ($canAddShop)
                {{-- Open, not behind the product page's disclosure: adding
                     shops is what this step is for. The form shows the
                     suggestions itself. --}}
                <flux:card>
                    @livewire('shops.add-shop', ['product' => $product], key('wizard-add-shop-' . $product->id))
                </flux:card>
            @else
                @include('livewire.shops.partials.shop-limit', ['shopCount' => $product->shops->count()])
            @endif

            <div class="flex flex-wrap gap-2">
                <flux:button type="button" wire:click="goToStep(1)">{{ __('Back') }}</flux:button>
                <flux:button type="button" variant="primary" wire:click="goToStep(3)">{{ __('Next') }}</flux:button>
            </div>
        </div>
    @else
        <form wire:submit="saveAlerts" class="space-y-6" data-test="wizard-alerts">
            <flux:text class="max-w-[65ch]" data-test="tracking-versus-alert">
                {{ __('We track every price either way. Your alert only decides when we notify you.') }}
            </flux:text>

            @php($showsSuggestion = $packChoices !== [] && ($asksJev || $suggestion->unitTarget !== null))
            <div class="min-w-0 max-w-2xl space-y-6">
                <h3 class="sr-only">{{ __('Your price alert') }}</h3>
                @if ($packChoices === [] && $pinnablePacks !== [])
                    {{-- No shop can be compared per unit yet: the price of the
                         pack the person buys, at the shop they pick. --}}
                    <x-pack-target :packs="$pinnablePacks" :currency="$product->currency" />
                @elseif ($packChoices !== [])
                    <x-unit-target
                        model="unitPriceTarget"
                        :heading="false"
                        :packs="$packChoices"
                        :currency="$product->currency"
                        :unit-word="$unitWord ?? __('unit')"
                        :stranded="$strandedOwnerSizes"
                        :suggested-unit="$showsSuggestion && ! $asksJev ? $suggestion->unitTarget : null"
                    >
                        @if ($showsSuggestion)
                            @if ($asksJev)
                                <x-slot:suggestion wire:init="askJev" wire:loading.attr="aria-busy" wire:target="askJev">
                                    @include('livewire.products.partials.alert-suggestion-row')
                                </x-slot:suggestion>
                            @else
                                <x-slot:suggestion wire:click="useSuggestion">
                                    @include('livewire.products.partials.alert-suggestion-row')
                                </x-slot:suggestion>
                            @endif
                        @endif
                    </x-unit-target>
                @endif

                @if (! $showsSuggestion)
                    <p class="max-w-[65ch] text-base/7 text-pretty text-zinc-600 sm:text-sm/6 dark:text-zinc-400" data-test="alert-suggestion-reason">
                        @if ($suggestion->band === \App\Enums\PromotionDepthBand::Fixed)
                            {{ __('This product has a fixed price, so we suggest no target: we tell you when the price drops well under its usual price.') }}
                        @elseif ($suggestion->unit === null)
                            {{ __('We could not read a pack size, so we suggest no target: we tell you when the price drops well under its usual price.') }}
                        @elseif ($suggestion->normalUnitPrice === null)
                            {{ __('We could not tell its normal price, so we suggest no target: we tell you when the price drops well under its usual price.') }}
                        @else
                            {{ __('We have no sign that this product goes on sale, so we suggest no target: we tell you when the price drops well under its usual price.') }}
                        @endif
                        @if ($onOfferNow)
                            {{ __('It is on offer now, so for its first weeks that counts from the offer price.') }}
                        @endif
                    </p>
                @endif

                @if (! $usesJev && $canSwitchOnAi)
                    <div data-test="switch-on-ai">
                        <livewire:ai-feature-prompt place="alert" below wire:key="ai-prompt-alert-wizard" />
                    </div>
                @elseif (! $usesJev)
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="pro-teaser">
                        {{ __('Pro also checks how products like this go on sale.') }}
                        <flux:link :href="route('app.billing')" wire:navigate>{{ __('Compare plans') }}</flux:link>
                    </flux:text>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button type="button" wire:click="goToStep(2)">{{ __('Back') }}</flux:button>
                <flux:button type="submit" variant="primary" data-test="start-tracking">{{ __('Start tracking') }}</flux:button>
            </div>
        </form>
    @endif
</div>
