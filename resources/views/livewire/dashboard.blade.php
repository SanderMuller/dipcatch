<div>
    <flux:heading size="xl" class="tracking-tight">{{ __('Dashboard') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
        {{ __('What you track, and what it has saved you so far.') }}
    </flux:text>

    {{-- One surface with dividers, not three cards: these three numbers are
         siblings in one context, so they need separation, not elevation. --}}
    <flux:card class="mt-6 p-0!">
        <dl class="grid divide-y divide-zinc-950/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10">
            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Tracked products') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $trackedProducts }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active on your watch list.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active drops') }}</dt>
                <dd @class([
                    'mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl',
                    'text-emerald-600 dark:text-emerald-400' => $activeDropCount > 0,
                ])>{{ $activeDropCount }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Below your threshold right now.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Lifetime savings') }}</dt>
                <dd class="mt-2 text-2xl font-semibold tracking-tight tabular-nums sm:text-3xl">{{ $lifetimeSavings }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Against the price each alert fired from.') }}</dd>
            </div>
        </dl>
    </flux:card>

    @unless ($hasAnyProduct)
        <flux:callout class="mt-6" icon="sparkles">
            <flux:callout.heading>{{ __('Track your first product') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Paste a product URL and DipCatch will watch it across every shop that sells it.') }}</flux:callout.text>
            @if ($canAddProduct)
                <flux:button class="mt-3 rounded-full!" :href="route('app.products.create')" variant="primary" wire:navigate>
                    {{ __('Track a product') }}
                </flux:button>
            @endif
        </flux:callout>
    @endunless

    @if ($watching->isNotEmpty())
        <div class="mt-8">
            <div class="flex items-end justify-between gap-3">
                <flux:heading size="lg">{{ __('Recently tracked') }}</flux:heading>
                <flux:link :href="route('app.products.index')" wire:navigate>{{ __('All products') }}</flux:link>
            </div>

            {{-- Cards here, dividers above: each tile navigates on its own, and
                 a card is the treatment for an independently interactive item. --}}
            <div class="@container mt-4">
                <ul role="list" class="grid gap-4 @md:grid-cols-2 @3xl:grid-cols-3">
                    @foreach ($watching as $product)
                        {{-- min-w-0: a grid track sizes to its content by default,
                             so a long title pushed the card past the viewport
                             on a phone. --}}
                        <li class="min-w-0" wire:key="watching-{{ $product->id }}">
                            {{-- An anchor carrying the card styling, not flux:card:
                                 that component always renders a div, so an href on
                                 it produces a tile nobody can click. --}}
                            <a
                                href="{{ route('app.products.show', $product) }}"
                                wire:navigate
                                class="flex h-full items-center gap-4 rounded-2xl bg-white/80 p-4 ring-1 ring-zinc-200 backdrop-blur-sm hover:bg-white dark:bg-zinc-900/60 dark:ring-zinc-800 dark:hover:bg-zinc-900"
                            >
                                <x-product-thumb :product="$product" size="size-14" />
                                <div class="min-w-0">
                                    <flux:text class="truncate font-medium">{{ Str::limit($product->title, 40) }}</flux:text>
                                    <flux:text size="sm" class="truncate text-zinc-500 tabular-nums">
                                        {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                                        @if ($product->cheapestShop)
                                            · {{ $product->cheapestShop->host }}
                                        @endif
                                    </flux:text>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="mt-8">
        <flux:heading size="lg">{{ __('Active drops') }}</flux:heading>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-start text-sm">
                <thead class="border-b border-zinc-950/10 dark:border-white/10">
                    <tr>
                        <th class="py-2 pe-3 text-start font-medium whitespace-nowrap">{{ __('Product') }}</th>
                        <th class="py-2 pe-3 text-start font-medium whitespace-nowrap">{{ __('Now') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium whitespace-nowrap md:table-cell">{{ __('Best value') }}</th>
                        <th class="hidden py-2 pe-3 text-start font-medium whitespace-nowrap md:table-cell">{{ __('Notified at') }}</th>
                        <th class="hidden py-2 text-start font-medium whitespace-nowrap md:table-cell">{{ __('Shop') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activeDrops as $product)
                        <tr class="border-b border-zinc-950/5 dark:border-white/5" wire:key="drop-{{ $product->id }}">
                            <td class="py-3 pe-3">
                                <a href="{{ route('app.products.show', $product) }}" wire:navigate class="flex items-center gap-3">
                                    <x-product-thumb :product="$product" size="size-12" />
                                    <flux:text class="font-medium">{{ Str::limit($product->title, 60) }}</flux:text>
                                </a>
                            </td>
                            <td class="py-3 pe-3 tabular-nums">
                                <flux:text class="font-medium text-emerald-600 dark:text-emerald-400">
                                    {{ \App\Support\MoneyFormatter::format($product->cheapest_price === null ? null : (string) $product->cheapest_price, $product->currency) }}
                                </flux:text>
                            </td>
                            <td class="hidden py-3 pe-3 tabular-nums md:table-cell">
                                {{ \App\Livewire\Products\ProductList::unitPriceState($product->bestValueShop(), $product) }}
                                @php($bestLabel = \App\Support\PromotionLabel::withHost($product->bestValueShop()))
                                @if ($bestLabel)
                                    <flux:text size="sm" class="text-zinc-500">{{ $bestLabel }}</flux:text>
                                @endif
                            </td>
                            <td class="hidden py-3 pe-3 tabular-nums text-zinc-500 md:table-cell">
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
    </div>
</div>
