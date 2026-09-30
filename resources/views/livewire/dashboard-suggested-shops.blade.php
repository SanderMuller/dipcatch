<section class="min-w-0" x-data="{ hideHost: '', hideLabel: '' }" data-test="suggested-shops">
    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Add suggested shops to your products') }}</flux:heading>
    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">
        {{ $aiChecked
            ? __('Other shops that sell your products, most likely match first. DipCatch checks the price again once you add one.')
            : __('Other shops that sell your products, from a daily supermarket list. DipCatch checks the price again once you add one.') }}
    </flux:text>

    @if ($rows === [])
        <div class="mt-4 rounded-2xl border border-dashed border-line px-6 py-10 text-center">
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No suggested shops right now.') }}</flux:text>
        </div>
    @else
        {{-- How sure the match is: Jev's chance, a barcode, or with the AI
             check on, a name match Jev has not answered yet. --}}
        @php($match = static fn (array $row): ?array => match (true) {
            $row['barcode'] => [__('Barcode match'), 'bg-savings/10 text-savings-strong'],
            $row['chance'] !== null => [__(':percent% match', ['percent' => (int) round($row['chance'] * 100)]), $row['chance'] >= 0.85 ? 'bg-savings/10 text-savings-strong' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'],
            $aiChecked => [__('Name match'), 'bg-zinc-950/5 text-zinc-600 dark:bg-white/10 dark:text-zinc-300'],
            default => null,
        })

        <div class="@container mt-4">
            <ul role="list" class="grid gap-3 @xl:grid-cols-2">
                @foreach (collect($rows)->groupBy(fn (array $row): string => (string) $row['product']->id) as $productRows)
                    @php($product = $productRows->first()['product'])
                    @php($cheapest = $product->cheapestShop)
                    <li class="rounded-2xl border border-ink/10 bg-paper p-3 dark:border-white/10" wire:key="suggested-{{ $product->id }}" data-test="suggested-product">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-product-thumb :product="$product" size="size-14" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('app.products.show', $product) }}" wire:navigate class="line-clamp-2 font-medium underline-offset-4 hover:underline">{{ $product->title }}</a>
                                @if ($cheapest?->current_price !== null)
                                    <p class="truncate text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                                        {{ __('Now :price at :shop', ['price' => \App\Support\MoneyFormatter::format($cheapest->current_price, $cheapest->currency ?? $product->currency), 'shop' => $cheapest->host]) }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        <ul role="list" class="mt-3 divide-y divide-ink/5 border-t border-ink/5 dark:divide-white/5 dark:border-white/5">
                            @foreach ($productRows as $row)
                                @php($badge = $match($row))
                                <li class="flex items-center gap-2 pt-2.5 not-last:pb-2.5" wire:key="suggested-{{ $product->id }}-{{ $row['host'] }}" data-test="suggested-shop">
                                    <img src="{{ \App\Support\Favicon::url($row['host']) }}" alt="" loading="lazy" class="size-5 shrink-0 rounded" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium" title="{{ $row['name'] }}">{{ $row['shop'] }}</p>
                                        @php($priceSource = $row['checkedOn'] !== null ? __('Price when checked on :date', ['date' => $row['checkedOn']->isoFormat('D MMM')]) : __('Regular price from the daily dataset'))
                                        <div class="flex min-w-0 items-center gap-2">
                                            <p class="truncate text-sm text-zinc-500 tabular-nums dark:text-zinc-400" title="{{ $priceSource }}">
                                                {{ implode(' · ', array_filter([$row['unitPrice'], $row['packPrice']])) }}<span class="sr-only">. {{ $priceSource }}</span>
                                            </p>
                                            @if ($badge !== null)
                                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $badge[1] }}">{{ $badge[0] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        square
                                        icon="arrow-top-right-on-square"
                                        :href="$row['url']"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        :aria-label="__('Open :shop in a new tab to check the product', ['shop' => $row['shop']])"
                                        :tooltip="__('Open the shop page')"
                                        data-test="suggested-shop-open"
                                    >
                                        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        square
                                        icon="plus"
                                        :href="route('app.products.show', [$product, 'add-shop' => 1])"
                                        wire:navigate
                                        :aria-label="__('Add :shop to :product', ['shop' => $row['shop'], 'product' => $product->title])"
                                        :tooltip="__('Add this shop')"
                                        data-test="suggested-shop-add"
                                    >
                                        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                                    </flux:button>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" :aria-label="__('Options for :shop', ['shop' => $row['shop']])" data-test="suggested-shop-more">
                                            <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                                        </flux:button>
                                        <flux:menu>
                                            <flux:menu.item icon="eye-slash" x-on:click="hideHost = {{ \Illuminate\Support\Js::from($row['host']) }}; hideLabel = {{ \Illuminate\Support\Js::from($row['shop']) }}; $flux.modal({{ \Illuminate\Support\Js::from('hide-shop-' . $this->getId()) }}).show()" data-test="suggested-shop-hide">
                                                {{ __('Don’t suggest :shop', ['shop' => $row['shop']]) }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>

        <x-hide-shop-confirm :name="'hide-shop-' . $this->getId()" />
    @endif
</section>
