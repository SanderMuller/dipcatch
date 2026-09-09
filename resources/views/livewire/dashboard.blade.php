<div>
    <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text size="sm" class="text-zinc-500">{{ __('Tracked products') }}</flux:text>
            <flux:heading size="xl">{{ $trackedProducts }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Active on your watch list.') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text size="sm" class="text-zinc-500">{{ __('Active drops') }}</flux:text>
            <flux:heading size="xl">{{ $activeDropCount }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Below your threshold right now.') }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:text size="sm" class="text-zinc-500">{{ __('Lifetime savings') }}</flux:text>
            <flux:heading size="xl">{{ $lifetimeSavings }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500">{{ __('Against the price each alert fired from.') }}</flux:text>
        </flux:card>
    </div>

    @unless ($hasAnyProduct)
        <flux:callout class="mt-6" icon="sparkles">
            <flux:callout.heading>{{ __('Track your first product') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Paste a product URL and DipCatch will watch it across every shop that sells it.') }}</flux:callout.text>
            @if ($canAddProduct)
                <flux:button class="mt-3" :href="route('app.products.create')" variant="primary" wire:navigate>
                    {{ __('Track a product') }}
                </flux:button>
            @endif
        </flux:callout>
    @endunless

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('Active drops') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Product') }}</th>
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Now') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Best value') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium md:table-cell">{{ __('Notified at') }}</th>
                        <th class="hidden py-2 text-start font-medium md:table-cell">{{ __('Shop') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activeDrops as $product)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="drop-{{ $product->id }}">
                            <td class="py-3 pe-3">
                                <a href="{{ route('app.products.show', $product) }}" wire:navigate>{{ Str::limit($product->title, 60) }}</a>
                            </td>
                            <td class="py-3 pe-3">
                                {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                            </td>
                            <td class="hidden py-3 pe-3 md:table-cell">
                                {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                                @php($bestLabel = \App\Support\PromotionLabel::withHost($product->bestValueShop()))
                                @if ($bestLabel)
                                    <flux:text size="sm" class="text-zinc-500">{{ $bestLabel }}</flux:text>
                                @endif
                            </td>
                            <td class="hidden py-3 pe-3 md:table-cell">
                                {{ \App\Support\MoneyFormatter::format($product->last_notified_price === null ? null : (string) $product->last_notified_price, $product->currency) }}
                            </td>
                            <td class="hidden py-3 md:table-cell">
                                @if ($product->cheapestShop) {!! \App\Support\Favicon::html($product->cheapestShop->host) !!} @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-10 text-center">
                                <flux:text class="text-zinc-500">{{ __('No active drops right now.') }}</flux:text>
                                <flux:text size="sm" class="text-zinc-400">{{ __("DipCatch is watching. We'll alert you when a price drops below your threshold.") }}</flux:text>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
