<div>
    <flux:breadcrumbs class="mb-4">
        <flux:breadcrumbs.item :href="route('app.products.index')" wire:navigate>{{ __('Products') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item :href="route('app.products.show', $product)" wire:navigate>{{ Str::limit($product->title, 40) }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <x-product-thumb :product="$product" size="size-16" />
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

    <form wire:submit="save">
        <flux:card class="mt-6">
            <flux:heading size="lg">{{ __('Product') }}</flux:heading>

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
                            <flux:text>{{ __('Suggested:') }} <span class="font-medium">{{ $suggestedLabel }}</span></flux:text>
                            <flux:button size="sm" variant="primary" wire:click="acceptSuggestion">{{ __('Use it') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="declineSuggestion">{{ __('Not this one') }}</flux:button>
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
                            <flux:link :href="route('upgrade')" class="text-sm">{{ __('Get Pro') }}</flux:link>
                        @endif
                    </div>
                @endif

                <flux:input wire:model="imageUrl" :label="__('Image URL')" type="url" placeholder="https://…" />

                @if ($shopImages !== [])
                    <div>
                        <flux:text size="sm" class="text-zinc-500">{{ __('Or take one a shop reported:') }}</flux:text>
                        <ul role="list" class="mt-2 flex flex-wrap gap-3">
                            @foreach ($shopImages as $url => $host)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="useShopImage({{ $loop->index }})"
                                        class="flex w-24 cursor-pointer flex-col items-center gap-1 rounded-xl bg-white/80 p-2 ring-1 ring-zinc-200 hover:bg-white dark:bg-zinc-900/60 dark:ring-zinc-800 dark:hover:bg-zinc-900"
                                    >
                                        <img src="{{ $url }}" alt="" loading="lazy" class="size-16 rounded-lg bg-white object-contain" />
                                        <span class="w-full truncate text-center text-xs text-zinc-500">{{ $host }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <flux:text size="sm" class="text-zinc-500">
                        {{ __('Shop images appear here after the next price check of each shop.') }}
                    </flux:text>
                @endif
            </div>
        </flux:card>

        <flux:card class="mt-6">
            <flux:heading size="lg">{{ __('Alerts') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Leave a field empty to switch that kind of alert off.') }}</flux:text>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="currency" variant="listbox" searchable :label="__('Currency')" :placeholder="__('Search currencies…')">
                    @foreach ($currencies as $code => $label)
                        <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="dropThresholdPct" :label="__('Alert me when it drops by (%)')" type="number" step="0.01" min="0.01" max="99.99" />

                <flux:input wire:model="dropThresholdAbs" :label="__('Alert me when it drops by (amount)')" type="number" step="0.01" min="0.01" />

                <flux:input
                    wire:model="targetPrice"
                    :label="__('Target price')"
                    {{-- The number to set a target against sits next to the box
                         rather than on another page. --}}
                    :description="$currentPrice
                        ? __('We tell you when any shop reaches this price. Now :amount at :host.', ['amount' => $currentPrice['amount'], 'host' => $currentPrice['host']])
                        : __('We tell you when any shop reaches this price.')"
                    type="number"
                    step="0.01"
                    min="0.01"
                />

                <flux:input
                    wire:model="unitPriceTarget"
                    {{-- The unit is named once the shops have read a pack size,
                         because by then it is not a choice the reader makes. --}}
                    :label="$unitWord
                        ? __('Target price per :unit', ['unit' => $unitWord])
                        : __('Target price per kilo, litre or piece')"
                    :description="$currentUnitPrice
                        ? $unitTargetDescription . ' ' . __('Now :amount at :host.', ['amount' => $currentUnitPrice['amount'], 'host' => $currentUnitPrice['host']])
                        : $unitTargetDescription"
                    type="number"
                    step="0.01"
                    min="0.01"
                />

                <div class="self-end">
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
