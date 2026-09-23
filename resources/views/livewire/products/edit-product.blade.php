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

            @if ($packChoices === [])
                {{-- No shop has said how much is in its pack yet, so there is
                     nothing to translate a pack price with. --}}
                <flux:input
                    class="mt-4"
                    wire:model="unitPriceTarget"
                    :label="$unitWord ? __('Target price per :unit', ['unit' => $unitWord]) : __('Target price per kilo, litre or piece')"
                    :description="$unitTargetDescription"
                    {{-- The column keeps four decimals; a step of 0.01 made the
                         browser refuse to submit a saved 0.0125. --}}
                    type="number"
                    step="any"
                    min="0.0001"
                />
            @else
                {{-- The per-unit target leads: it is the alert that holds every
                     shop and pack size to one rate. --}}
                <x-unit-target
                    class="mt-6 max-w-2xl"
                    model="unitPriceTarget"
                    :description="trim(($allowsUnitPriceAlerts ? '' : $unitTargetDescription) . ' ' . ($currentUnitPrice
                        ? __('Now :amount at :host.', ['amount' => $currentUnitPrice['amount'], 'host' => $currentUnitPrice['host']])
                        : ''))"
                    :upgrade="! $allowsUnitPriceAlerts"
                    :packs="$packChoices"
                    :history="$unitHistory"
                    :currency="$product->currency ?? 'EUR'"
                    :unit-word="$unitWord ?? __('unit')"
                />
            @endif

            {{-- Folded away while empty, so the price alert leads; open when
                 one of them is set or has an error, so nothing active hides. --}}
            @php($otherAlerts = array_values(array_filter([
                $targetPrice !== null && $targetPrice !== '' ? __(':amount for any pack', ['amount' => \App\Support\MoneyFormatter::format((string) $targetPrice, (string) $currency)]) : null,
                $dropThresholdPct !== null && $dropThresholdPct !== '' ? __(':percent% drop', ['percent' => \App\Support\Numeric::trimmed((string) $dropThresholdPct)]) : null,
                $dropThresholdAbs !== null && $dropThresholdAbs !== '' ? __(':amount drop', ['amount' => \App\Support\MoneyFormatter::format((string) $dropThresholdAbs, (string) $currency)]) : null,
            ])))
            @php($otherAlertErrors = $errors->hasAny(['dropThresholdPct', 'dropThresholdAbs', 'targetPrice']))
            {{-- wire:ignore.self keeps the section open or shut across round
                 trips; the summary line, which does morph, names an error. --}}
            <details
                class="group mt-8 border-t border-zinc-950/5 pt-6 dark:border-white/10"
                wire:ignore.self
                @if ($otherAlertErrors || $otherAlerts !== []) open @endif
                data-test="other-alerts"
            >
                <summary class="flex cursor-pointer list-none items-center gap-2 select-none [&::-webkit-details-marker]:hidden">
                    <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 group-open:rotate-90" />
                    <span class="text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">{{ __('Other alerts') }}</span>
                    <span class="truncate text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
                        · {{ $otherAlerts === [] ? __('a drop in percent or money, or a price for any pack') : implode(', ', $otherAlerts) }}
                    </span>
                    @if ($otherAlertErrors)
                        <span class="shrink-0 text-base/7 font-medium text-red-600 sm:text-sm/6 dark:text-red-400">{{ __('Check these') }}</span>
                    @endif
                </summary>
                {{-- Beside the drop it replaces, so it reads as a swap rather
                     than as one more alert. The drop fields update on blur, so
                     the figures here follow what was typed. --}}
                @if ($priceAlertSwitch)
                    <flux:callout class="mt-4" color="blue" icon="arrows-right-left" data-test="price-alert-switch">
                        <flux:callout.heading>{{ __('Switch this drop alert to a price alert') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Instead of your :drop drop alert, get an alert once any shop sells it at :unit per :word or less — :pack for your :size, :percent% under today’s best.', [
                                'drop' => implode(' ' . __('or') . ' ', $priceAlertSwitch['drops']),
                                'percent' => $priceAlertSwitch['percentUnder'],
                                'unit' => \App\Support\MoneyFormatter::unitPrice($priceAlertSwitch['unit'], (string) $currency),
                                'word' => $unitWord ?? __('unit'),
                                'pack' => \App\Support\MoneyFormatter::format($priceAlertSwitch['packPrice'], (string) $currency),
                                'size' => $priceAlertSwitch['pack'],
                            ]) }}
                            {{ __('Your own drop settings are cleared, so drops go back to the default for the price.') }}
                        </flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="switchToPriceAlert">{{ __('Switch to a price alert') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model.blur="dropThresholdPct" :label="__('Alert me when it drops by (%)')" type="number" step="0.01" min="0.01" max="99.99" />

                    <flux:input wire:model.blur="dropThresholdAbs" :label="__('Alert me when it drops by (amount)')" type="number" step="0.01" min="0.01" />

                    <flux:input wire:model="targetPrice" :label="__('Price for any pack')" type="number" step="0.01" min="0.01" />
                </div>
                <p class="mt-3 max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400">
                    {{ $currentPrice
                        ? __('We tell you when any shop reaches this price. Now :amount at :host.', ['amount' => $currentPrice['amount'], 'host' => $currentPrice['host']])
                        : __('We tell you when any shop reaches this price.') }}
                    {{ __('Leave a target empty to switch it off. Leave a drop empty to use the default for the price.') }}
                </p>
            </details>

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
