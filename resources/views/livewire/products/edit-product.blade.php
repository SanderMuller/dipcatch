<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('app.products.show', $product)" wire:navigate>{{ Str::limit($product->title, 40) }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <x-product-thumb :product="$product" thumbnail size="size-16" />
            <div class="min-w-0">
                <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Edit product') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ $product->title }}</flux:text>
            </div>
        </div>

        <flux:button size="sm" :href="route('app.products.show', $product)" wire:navigate icon="arrow-uturn-left">
            {{ __('Back to product') }}
        </flux:button>
    </div>

    @if ($message)
        <flux:callout class="mt-6" icon="information-circle">{{ $message }}</flux:callout>
    @endif

    {{-- Always in the page, so a screen reader announces what lands in it. --}}
    <div role="status" class="sr-only" data-test="edit-status">{{ $status }}</div>

    <form wire:submit="save">
        <flux:card class="mt-6">
            <flux:heading size="lg" level="2">{{ __('Product') }}</flux:heading>

            <div class="mt-4 space-y-4">
                <flux:input wire:model="title" :label="__('Title')" required />

                <flux:select wire:model="category" :label="__('Category')" data-test="product-category">
                    <flux:select.option value="">{{ __('No category') }}</flux:select.option>
                    @foreach ($categoryGroups as $departmentValue => $categories)
                        <flux:select.group :label="\App\Enums\ProductDepartment::from($departmentValue)->label()">
                            @foreach ($categories as $leaf)
                                <flux:select.option value="{{ $leaf->value }}">{{ $leaf->label() }}</flux:select.option>
                            @endforeach
                        </flux:select.group>
                    @endforeach
                </flux:select>

                @if ($suggestionAvailable && $category === '')
                    <div class="flex flex-wrap items-center gap-3" data-test="category-suggestion">
                        @if ($suggestedCategory !== null)
                            <x-category-suggestion :label="$suggestedLabel" accept="acceptSuggestion" decline="declineSuggestion" />
                        @elseif ($allowsAutoCategories)
                            <flux:button size="sm" icon="sparkles" wire:click="suggestCategory" wire:loading.attr="disabled" wire:target="suggestCategory">
                                {{ __('Suggest a category') }}
                            </flux:button>
                            <flux:text size="sm" class="text-zinc-500" wire:loading wire:target="suggestCategory">{{ __('Looking at the product…') }}</flux:text>
                            @if ($suggestionMessage)
                                <flux:text size="sm" class="text-zinc-500" wire:loading.remove wire:target="suggestCategory">{{ $suggestionMessage }}</flux:text>
                            @endif
                        @else
                            {{-- Disabled, not hidden: the button is the pitch. --}}
                            <flux:tooltip :content="__('Pro suggests a category for you.')">
                                <flux:button size="sm" icon="sparkles" disabled>{{ __('Suggest a category') }}</flux:button>
                            </flux:tooltip>
                            <flux:link :href="route('app.pro')" class="text-sm">{{ __('Get Pro') }}</flux:link>
                        @endif
                    </div>
                    {{-- Mounted only when offered, so the form keeps no empty gap. --}}
                    @if ($offersAutoCategories)
                        <livewire:ai-feature-prompt place="product_category" wire:key="ai-prompt-product-category" />
                    @endif
                @endif

                {{-- Only when a price per unit exists to show. Without a pack
                     size on any shop there is nothing to choose between. --}}
                @if ($comparisonUnit !== null)
                    <flux:select wire:model="priceDisplay" :label="__('Show the price as')" data-test="product-price-display"
                        :description="__('Shops are always compared on the price :unit.', ['unit' => \App\Support\UnitWord::forCode($comparisonUnit)])">
                        <flux:select.option value="">{{ __('Automatic: the pack price, or the price :unit once the shops sell different packs', ['unit' => \App\Support\UnitWord::forCode($comparisonUnit)]) }}</flux:select.option>
                        <flux:select.option value="pack">{{ __('The pack price') }}</flux:select.option>
                        <flux:select.option value="unit">{{ __('The price :unit', ['unit' => \App\Support\UnitWord::forCode($comparisonUnit)]) }}</flux:select.option>
                    </flux:select>
                @endif

                <flux:input wire:model="imageUrl" :label="__('Image URL')" type="url" placeholder="https://…" />

                <x-shop-image-picker :images="$shopImages" />
            </div>
        </flux:card>

        <flux:card class="mt-6">
            <flux:heading size="lg" level="2">{{ __('Alerts') }}</flux:heading>

            @php($showsSuggestion = $alertCard !== null && ($alertCard['asksJev'] || $alertCard['suggestion']->unitTarget !== null))
            <div class="mt-4 min-w-0">
                @if ($suspendedUnitTarget !== null)
                    {{-- One button that toggles in place, so keyboard focus stays on it. --}}
                    <div class="max-w-2xl" data-test="suspended-unit-target">
                        @if ($removeUnitPriceTarget)
                            <flux:text size="sm">{{ __('Your target of :target will be removed when you save.', ['target' => $suspendedUnitTarget['target']]) }}</flux:text>
                        @else
                            <flux:text size="sm" class="text-amber-700 dark:text-amber-500">
                                @if ($suspendedUnitTarget['waitsForSize'])
                                    {{ __('Your target of :target stays until that shop says how much is in its pack. Then it becomes a price per :unit. Set a price below to replace it, or remove it.', ['target' => $suspendedUnitTarget['target'], 'unit' => $unitWord ?? __('unit')]) }}
                                @elseif ($suspendedUnitTarget['pack'])
                                    {{ __('Your target of :target is paused: that shop is sold out or no longer tracked. Pick the pack you buy below, or remove it.', ['target' => $suspendedUnitTarget['target']]) }}
                                @elseif ($packChoices === [])
                                    {{ __('Your target of :target is paused: no shop can be compared per unit right now. Set a price for the pack you buy below, or remove it.', ['target' => $suspendedUnitTarget['target']]) }}
                                @else
                                    {{ __('Your target of :target is paused: this product now compares :unit. Set a new target below, or remove it.', ['target' => $suspendedUnitTarget['target'], 'unit' => $suspendedUnitTarget['comparesIn']]) }}
                                @endif
                            </flux:text>
                        @endif
                        <flux:button size="sm" variant="ghost" class="mt-2" wire:click="{{ $removeUnitPriceTarget ? 'keepUnitTarget' : 'removeUnitTarget' }}" data-test="remove-unit-target">
                            {{ $removeUnitPriceTarget ? __('Keep target') : __('Remove target') }}
                        </flux:button>
                    </div>
                @endif

                @if ($packChoices === [])
                    {{-- No shop can be compared per unit yet: the price of the
                         pack the person buys, at the shop they pick. --}}
                    @if ($pinnablePacks !== [])
                        <x-pack-target
                            class="mt-6 max-w-2xl"
                            :packs="$pinnablePacks"
                            :currency="$product->currency ?? 'EUR'"
                        />
                    @else
                        <flux:text class="mt-4 max-w-2xl" data-test="no-pack-to-target">
                            {{ __('No shop has a price you can buy at right now. We tell you when the price drops well under its usual price.') }}
                        </flux:text>
                    @endif
                @else
                    {{-- The per-unit target leads: it is the alert that holds every
                         shop and pack size to one rate. The suggested alert is one
                         of its choices. --}}
                    <x-unit-target
                        class="mt-6 max-w-2xl"
                        model="unitPriceTarget"
                        :description="$currentUnitPrice
                            ? __('Now :amount at :host.', ['amount' => $currentUnitPrice['amount'], 'host' => \App\Support\ShopNames::label($currentUnitPrice['host'])])
                            : ''"
                        :packs="$packChoices"
                        :history="$unitHistory"
                        :currency="$product->currency ?? 'EUR'"
                        :unit-word="$unitWord ?? __('unit')"
                        :item-size="$itemSize"
                        :stranded="$strandedOwnerSizes"
                        :suggested-unit="$showsSuggestion && ! $alertCard['asksJev'] ? $alertCard['suggestion']->unitTarget : null"
                    >
                        @if ($showsSuggestion)
                            @if ($alertCard['asksJev'])
                                <x-slot:suggestion wire:init="askJev" wire:loading.attr="aria-busy" wire:target="askJev">
                                    @include('livewire.products.partials.alert-suggestion-row', $alertCard)
                                </x-slot:suggestion>
                            @else
                                <x-slot:suggestion wire:click="useSuggestion">
                                    @include('livewire.products.partials.alert-suggestion-row', $alertCard)
                                </x-slot:suggestion>
                            @endif
                        @endif
                    </x-unit-target>

                    @if ($showsSuggestion && ! $alertCard['usesJev'] && $alertCard['canSwitchOnAi'])
                        <div class="max-w-2xl" data-test="switch-on-ai">
                            <livewire:ai-feature-prompt place="alert" below wire:key="ai-prompt-alert-edit" />
                        </div>
                    @elseif ($showsSuggestion && ! $alertCard['usesJev'])
                        <flux:text size="sm" class="mt-3 max-w-2xl text-zinc-500 dark:text-zinc-400" data-test="pro-teaser">
                            {{ __('Pro also checks how products like this go on sale.') }}
                            <flux:link :href="route('app.billing')" wire:navigate>{{ __('Compare plans') }}</flux:link>
                        </flux:text>
                    @endif
                @endif
            </div>

            {{-- Currency sits with the settings every alert shares, not with one
                 kind of alert. --}}
            <div class="mt-8 flex flex-wrap items-end justify-between gap-4 border-t border-zinc-950/5 pt-6 dark:border-white/10">
                <flux:select class="max-w-xs" wire:model="currency" variant="listbox" searchable :label="__('Currency')" :placeholder="__('Search currencies…')">
                    @foreach ($currencies as $code => $label)
                        <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="pb-2">
                    <flux:switch wire:model="active" :label="__('Tracking active')" />
                </div>
            </div>
        </flux:card>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" class="rounded-full!">{{ __('Save changes') }}</flux:button>
                <flux:button variant="ghost" :href="route('app.products.show', $product)" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>

            {{-- Deliberately quiet, and away from Save: this removes the price
                 history with the product. --}}
            <flux:button
                variant="subtle"
                icon="trash"
                wire:click="delete"
                wire:confirm="{{ __('Delete this product? Its shops and price history go with it.') }}"
            >
                {{ __('Delete product') }}
            </flux:button>
        </div>
    </form>
</div>
