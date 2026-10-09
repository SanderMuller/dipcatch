<div class="print:text-[11pt] print:text-black">
    @php($shopCount = count(array_filter($list->groups, fn (array $group): bool => $group['host'] !== '' && $group['open'] > 0)))

    <div class="flex flex-wrap items-end justify-between gap-4 print:mb-6 print:border-b-2 print:border-black print:pb-3">
        <div>
            <flux:heading size="xl" level="1" id="shopping-list-heading" tabindex="-1" class="text-2xl! font-semibold! tracking-tight focus:outline-none sm:text-3xl! print:text-[20pt]! print:text-black!">{{ __('Shopping list') }}</flux:heading>
            @unless ($list->isEmpty())
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400 print:text-zinc-700!" data-test="shopping-list-open-count">
                    <span class="hidden print:inline">{{ now()->translatedFormat('l j F Y') }} · </span>
                    {{ trans_choice(':count to buy|:count to buy', $list->openCount, ['count' => $list->openCount]) }}@if ($shopCount > 1) · {{ trans_choice('at :count shop|at :count shops', $shopCount, ['count' => $shopCount]) }}@endif
                </flux:text>
            @endunless
        </div>

        @unless ($list->isEmpty())
            <div class="flex items-center gap-2 print:hidden">
                @if ($list->crossedOffCount > 0)
                    <flux:modal.trigger name="clear-crossed-off">
                        <flux:button size="sm" variant="ghost" icon="trash" data-test="shopping-list-clear">
                            {{ __('Clear crossed off (:count)', ['count' => $list->crossedOffCount]) }}
                        </flux:button>
                    </flux:modal.trigger>
                @endif
                <flux:button size="sm" icon="printer" x-on:click="window.print()" data-test="shopping-list-print">{{ __('Print') }}</flux:button>
            </div>
        @endunless
    </div>

    @if (count($list->shops) > 1)
        {{-- Which shops this trip goes to. A skipped shop's products move to
             their best buy at the shops that are left. --}}
        <div class="mt-5 print:hidden" data-test="shopping-list-shops">
            <p id="shopping-list-shops-label" class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Shops you are going to') }}</p>
            <div class="mt-2 flex flex-wrap gap-2" role="group" aria-labelledby="shopping-list-shops-label">
                {{-- The number is what is listed under the shop, as its group
                     says, not every item it sells: a shop that sells an item
                     another shop has cheaper would otherwise count it too. --}}
                @php($toBuyAt = array_column(array_filter($list->groups, fn (array $group): bool => $group['host'] !== ''), 'open', 'host'))
                @foreach ($list->shops as $shop)
                    @php($going = ! in_array($shop['host'], $skip, true))
                    @php($toBuy = $toBuyAt[$shop['host']] ?? 0)
                    <button
                        type="button"
                        wire:click="toggleShop(@js($shop['host']))"
                        wire:key="shop-choice-{{ $shop['host'] }}"
                        aria-pressed="{{ $going ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full py-1 pr-3 pl-2 text-sm font-medium ring-1 transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand',
                            'bg-paper text-zinc-900 shadow-xs ring-line hover:bg-canvas dark:text-white' => $going,
                            'bg-transparent text-zinc-500 line-through ring-zinc-300 ring-dashed hover:text-zinc-700 dark:text-zinc-400 dark:ring-white/15' => ! $going,
                        ])
                        data-test="shopping-list-shop-toggle"
                    >
                        @if ($going)
                            <flux:icon.check variant="micro" class="size-4 text-savings-strong" />
                        @else
                            <flux:icon.x-mark variant="micro" class="size-4" />
                        @endif
                        <span class="inline-flex items-center">{!! \App\Support\Favicon::html($shop['host']) !!}</span>
                        @if ($going && $toBuy > 0)
                            <span class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400" title="{{ trans_choice(':count to buy here|:count to buy here', $toBuy, ['count' => $toBuy]) }}">{{ $toBuy }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($list->isEmpty())
        <flux:card class="mt-8 text-center" data-test="shopping-list-empty">
            <flux:icon.list-bullet class="mx-auto size-8 text-zinc-400" />
            <flux:heading size="lg" class="mt-3">{{ __('Your shopping list is empty.') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add products from their page with Add to shopping list.') }}</flux:text>
            <flux:button size="sm" class="mt-4" :href="route('app.products.index')" wire:navigate>{{ __('Go to your products') }}</flux:button>
        </flux:card>
    @else
        <div class="mt-6 grid gap-5 print:mt-0 print:block">
            @foreach ($list->groups as $group)
                {{-- A plain section rather than flux:card: the card's own border
                     and radius survive print styles and box every group. --}}
                <section
                    wire:key="shopping-group-{{ $group['skipped'] ? 'skipped' : ($group['host'] === '' ? 'none' : $group['host']) }}"
                    @class([
                        'overflow-hidden rounded-2xl bg-paper shadow-xs ring-1 ring-line dark:shadow-none' => ! $group['skipped'],
                        // Not a stop on this trip: dashed and amber, so it
                        // reads as left over rather than as another shop.
                        'overflow-hidden rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50/60 dark:border-amber-500/40 dark:bg-amber-950/20' => $group['skipped'],
                        'print:mb-6 print:overflow-visible print:rounded-none print:bg-transparent print:shadow-none print:ring-0',
                        'print:hidden' => $group['open'] === 0,
                    ])
                    data-test="shopping-list-group"
                >
                    <div @class([
                        'flex items-center justify-between gap-3 border-b px-4 py-2.5 break-after-avoid print:border-black print:bg-transparent print:px-0 print:py-1',
                        'border-line bg-zinc-50/70 dark:bg-white/5' => ! $group['skipped'],
                        'border-amber-200 bg-amber-100/60 text-amber-900 dark:border-amber-500/30 dark:bg-amber-900/30 dark:text-amber-100' => $group['skipped'],
                    ])>
                        <h2 class="flex min-w-0 items-center gap-2 text-base font-semibold print:text-[13pt]">
                            @if ($group['skipped'])
                                <flux:icon.exclamation-triangle variant="micro" class="size-4 text-amber-600 dark:text-amber-400" />
                                <span>{{ __('Only at shops you skip') }}</span>
                                <span class="text-sm font-normal text-amber-800/80 dark:text-amber-200/80 print:hidden">{{ __('Not on this trip') }}</span>
                            @elseif ($group['host'] === '')
                                <flux:icon.exclamation-circle variant="micro" class="size-4 text-zinc-400" />
                                <span>{{ __('No shop sells this now') }}</span>
                            @else
                                {!! \App\Support\Favicon::html($group['host']) !!}
                            @endif
                        </h2>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 tabular-nums dark:bg-white/10 dark:text-zinc-300 print:bg-transparent print:p-0 print:text-[9pt] print:text-zinc-600">
                            {{ trans_choice(':count to buy|:count to buy', $group['open'], ['count' => $group['open']]) }}
                        </span>
                    </div>

                    <ul role="list" class="divide-y divide-line print:divide-zinc-300">
                        @foreach ($group['items'] as $item)
                            @php($product = $item['product'])
                            @php($deal = \App\Support\PromotionLabel::runningDeal($item['shop']))
                            @php($packLine = $item['shop'] === null || ! $item['headline']->isPerUnit() ? null : $item['headline']->packLine())
                            @php($unitLine = $item['shop'] === null ? null : $item['headline']->unitLine())
                            <li
                                wire:key="shopping-item-{{ $product->id }}"
                                @class([
                                    'flex items-center gap-3 px-4 py-3 break-inside-avoid print:px-0 print:py-2',
                                    'bg-zinc-50/60 dark:bg-white/[0.02] print:hidden' => $item['crossedOff'],
                                ])
                                data-test="shopping-list-item"
                            >
                                <input
                                    type="checkbox"
                                    id="shopping-item-{{ $product->id }}"
                                    class="size-5 shrink-0 cursor-pointer rounded-md border-zinc-300 text-brand focus:ring-brand print:hidden dark:border-zinc-600 dark:bg-zinc-800"
                                    @checked($item['crossedOff'])
                                    wire:click="toggleCrossedOff('{{ $product->id }}')"
                                    aria-label="{{ $item['crossedOff'] ? __('Put :title back on the list', ['title' => $product->title]) : __('Cross off :title', ['title' => $product->title]) }}"
                                />
                                <span class="hidden size-[5mm] shrink-0 rounded-[1mm] border-[1.5pt] border-black print:inline-block" aria-hidden="true"></span>

                                <x-product-thumb :product="$product" thumbnail size="size-12" @class(['print:hidden', 'opacity-50 grayscale' => $item['crossedOff']]) />

                                <div class="min-w-0 flex-1">
                                    <a
                                        href="{{ route('app.products.show', $product) }}"
                                        wire:navigate
                                        @class([
                                            'block truncate font-medium underline-offset-4 hover:underline print:whitespace-normal print:text-black print:no-underline',
                                            'text-zinc-500 line-through dark:text-zinc-400' => $item['crossedOff'],
                                        ])
                                    >{{ $product->title }}</a>
                                    @if ($item['skippedBest'] !== null && ! $item['crossedOff'])
                                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-amber-900 dark:text-amber-200 print:text-[9pt]">
                                            <span>{{ __('Sold at :host', ['host' => $item['skippedBest']->host]) }}</span>
                                            <button
                                                type="button"
                                                wire:click="toggleShop(@js($item['skippedBest']->host))"
                                                class="rounded-full bg-white px-2 py-0.5 font-medium text-amber-900 ring-1 ring-amber-300 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-500/40 print:hidden"
                                                data-test="shopping-list-unskip"
                                            >{{ __('Go to :host too', ['host' => $item['skippedBest']->host]) }}</button>
                                        </p>
                                    @endif
                                    @if (! $item['crossedOff'] && ($packLine !== null || $unitLine !== null || $deal !== null))
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-zinc-500 dark:text-zinc-400 print:text-[9pt] print:text-zinc-700">
                                            @if ($packLine !== null)
                                                <span class="tabular-nums">{{ \App\Support\PackLine::format($packLine->price, $packLine->shop->currency, $packLine->size) }}</span>
                                            @elseif ($unitLine !== null)
                                                <span class="tabular-nums">{{ $unitLine }}</span>
                                            @endif
                                            @if ($deal !== null)
                                                <span class="rounded-full bg-savings/10 px-1.5 py-px font-medium text-savings-strong print:bg-transparent print:p-0 print:font-semibold print:text-black">{{ $deal }}</span>
                                            @endif
                                        </p>
                                    @endif
                                </div>

                                <span
                                    @class([
                                        'shrink-0 text-right text-sm font-semibold tabular-nums print:text-[11pt] print:text-black',
                                        'text-brand' => ! $item['crossedOff'],
                                        'font-normal text-zinc-500 dark:text-zinc-400' => $item['crossedOff'],
                                    ])
                                    data-test="shopping-list-price"
                                >
                                    {{ $item['shop'] === null ? '' : $item['headline']->text() }}
                                </span>

                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="x-mark"
                                    class="print:hidden"
                                    wire:click="remove('{{ $product->id }}')"
                                    x-on:click="document.getElementById('shopping-list-heading')?.focus()"
                                    aria-label="{{ __('Remove :title from shopping list', ['title' => $product->title]) }}"
                                />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <p class="mt-8 hidden text-[8pt] text-zinc-500 print:block">{{ __('Prices as DipCatch last read them. Check the shelf before you buy.') }} · dipcatch.eu</p>
    @endif

    <flux:modal name="clear-crossed-off" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ trans_choice('Remove :count crossed-off item from the list?|Remove :count crossed-off items from the list?', $list->crossedOffCount, ['count' => $list->crossedOffCount]) }}</flux:heading>
                <flux:text class="mt-2">{{ __('The products stay tracked. Only the list forgets them.') }}</flux:text>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="clearCrossedOff" data-test="shopping-list-clear-confirm">{{ __('Clear crossed off') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
