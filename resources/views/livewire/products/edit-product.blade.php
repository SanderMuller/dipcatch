<div>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <x-product-thumb :product="$product" size="size-16" />
            <div class="min-w-0">
                <flux:heading size="xl" class="tracking-tight">{{ __('Edit product') }}</flux:heading>
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
                <flux:select wire:model="currency" :label="__('Currency')">
                    @foreach ($currencies as $code => $label)
                        <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="dropThresholdPct" :label="__('Drop threshold (%)')" type="number" step="0.01" min="0.01" max="99.99" />

                <flux:input wire:model="dropThresholdAbs" :label="__('Drop threshold (amount)')" type="number" step="0.01" min="0.01" />

                <flux:input
                    wire:model="targetPrice"
                    :label="__('Target price')"
                    :description="__('Alerts when the cheapest shop reaches this price.')"
                    type="number"
                    step="0.01"
                    min="0.01"
                />

                <flux:input
                    wire:model="unitPriceTarget"
                    :label="__('Unit price target')"
                    :description="$allowsUnitPriceAlerts
                        ? __('Alerts when the best value reaches this price per kilo, litre or piece.')
                        : __('Pro alerts on this. The number is kept and starts working when you upgrade.')"
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
