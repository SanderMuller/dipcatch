<div class="print:text-black">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" id="shopping-list-heading" tabindex="-1" class="text-2xl! font-semibold! tracking-tight focus:outline-none sm:text-3xl! print:hidden">{{ __('Shopping list') }}</flux:heading>
            <p class="hidden text-xl font-semibold print:block">{{ __('Shopping list, :date', ['date' => now()->translatedFormat('j F Y')]) }}</p>
            @unless ($list->isEmpty())
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400 print:hidden" data-test="shopping-list-open-count">
                    {{ trans_choice(':count to buy|:count to buy', $list->openCount, ['count' => $list->openCount]) }}
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

    @if ($list->isEmpty())
        <flux:card class="mt-8 text-center" data-test="shopping-list-empty">
            <flux:icon.list-bullet class="mx-auto size-8 text-zinc-400" />
            <flux:heading size="lg" class="mt-3">{{ __('Your shopping list is empty.') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Add products from their page with Add to shopping list.') }}</flux:text>
            <flux:button size="sm" class="mt-4" :href="route('app.products.index')" wire:navigate>{{ __('Go to your products') }}</flux:button>
        </flux:card>
    @else
        <div class="mt-6 grid gap-4 print:mt-4 print:gap-6">
            @foreach ($list->groups as $group)
                <flux:card
                    wire:key="shopping-group-{{ $group['host'] === '' ? 'none' : $group['host'] }}"
                    @class(['p-0! print:border-0 print:shadow-none', 'print:hidden' => $group['open'] === 0])
                    data-test="shopping-list-group"
                >
                    <div class="flex items-center justify-between gap-3 border-b border-ink/5 px-4 py-3 break-after-avoid print:border-0 print:px-0 print:py-1">
                        <h2 class="flex min-w-0 items-center font-semibold">
                            @if ($group['host'] === '')
                                <span>{{ __('No shop sells this now') }}</span>
                            @else
                                {!! \App\Support\Favicon::html($group['host']) !!}
                            @endif
                        </h2>
                        <span class="shrink-0 text-sm text-zinc-500 dark:text-zinc-400 print:hidden">{{ trans_choice(':count to buy|:count to buy', $group['open'], ['count' => $group['open']]) }}</span>
                    </div>

                    <ul role="list" class="divide-y divide-ink/5 print:divide-y-0">
                        @foreach ($group['items'] as $item)
                            @php($product = $item['product'])
                            @php($deal = \App\Support\PromotionLabel::runningDeal($item['shop']))
                            <li
                                wire:key="shopping-item-{{ $product->id }}"
                                @class(['flex items-center gap-3 px-4 py-3 print:px-0 print:py-1', 'print:hidden' => $item['crossedOff']])
                                data-test="shopping-list-item"
                            >
                                <input
                                    type="checkbox"
                                    id="shopping-item-{{ $product->id }}"
                                    class="size-5 shrink-0 rounded border-zinc-300 text-brand focus:ring-brand print:hidden dark:border-zinc-600 dark:bg-zinc-800"
                                    @checked($item['crossedOff'])
                                    wire:click="toggleCrossedOff('{{ $product->id }}')"
                                    aria-label="{{ $item['crossedOff'] ? __('Put :title back on the list', ['title' => $product->title]) : __('Cross off :title', ['title' => $product->title]) }}"
                                />
                                <span class="hidden size-4 shrink-0 border border-black print:inline-block" aria-hidden="true"></span>

                                <x-product-thumb :product="$product" size="size-10" class="print:hidden" />

                                <div class="min-w-0 flex-1">
                                    <a
                                        href="{{ route('app.products.show', $product) }}"
                                        wire:navigate
                                        @class([
                                            'block truncate font-medium underline-offset-4 hover:underline print:whitespace-normal print:no-underline',
                                            'text-zinc-500 line-through dark:text-zinc-400' => $item['crossedOff'],
                                        ])
                                    >{{ $product->title }}</a>
                                    @if ($deal !== null && ! $item['crossedOff'])
                                        <p class="truncate text-xs text-savings-strong print:whitespace-normal">{{ $deal }}</p>
                                    @endif
                                </div>

                                <span @class(['shrink-0 text-sm tabular-nums', 'text-zinc-500 dark:text-zinc-400' => $item['crossedOff']]) data-test="shopping-list-price">
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
                </flux:card>
            @endforeach
        </div>
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
