{{-- Polls, as the bell does, for changes the page's own event cannot reach:
     another tab, and a price check that moves a best buy or a price. --}}
<flux:dropdown position="bottom" align="end" wire:poll.60s data-test="shopping-list-menu">
    <flux:button variant="ghost" size="sm" icon="list-bullet" id="shopping-list-trigger" class="relative" aria-label="{{ $openCount > 0 ? trans_choice('Shopping list, :count to buy|Shopping list, :count to buy', $openCount, ['count' => $openCount]) : __('Shopping list') }}">
        @if ($openCount > 0)
            <flux:badge color="zinc" size="sm" class="absolute -end-1 -top-1" data-test="shopping-list-badge">{{ $openCount > 9 ? '9+' : $openCount }}</flux:badge>
        @endif
    </flux:button>

    <flux:menu class="w-80">
        <div class="px-2 py-1.5">
            <flux:heading size="sm">{{ __('Shopping list') }}</flux:heading>
        </div>

        <flux:menu.separator />

        @if ($listedCount === 0)
            <div class="px-3 py-6 text-center">
                <flux:text class="text-zinc-500">{{ __('Nothing on your list yet.') }}</flux:text>
                <flux:text size="sm" class="text-zinc-500">{{ __('Add products from their page with Add to shopping list.') }}</flux:text>
            </div>
        @else
            @if ($openCount === 0)
                <div @class(['px-3 text-center', 'py-6' => $items === [], 'py-2' => $items !== []])>
                    <flux:text class="text-zinc-500">{{ __('Everything is crossed off.') }}</flux:text>
                </div>
            @endif

            {{-- The row is not one menu item: the checkbox and the remove button
                 act here, and only the product opens its page. --}}
            @foreach ($items as $item)
                @php($product = $item['product'])
                <div class="flex items-center gap-1 rounded-md" wire:key="shopping-menu-{{ $product->id }}" data-test="shopping-menu-item">
                    <input
                        type="checkbox"
                        class="ms-2 size-4 shrink-0 cursor-pointer rounded border-zinc-300 text-brand focus:ring-brand dark:border-zinc-600 dark:bg-zinc-800"
                        @checked($item['crossedOff'])
                        wire:click="toggleCrossedOff('{{ $product->id }}')"
                        aria-label="{{ $item['crossedOff'] ? __('Put :title back on the list', ['title' => $product->title]) : __('Cross off :title', ['title' => $product->title]) }}"
                        data-test="shopping-menu-cross-off"
                    />
                    <flux:menu.item :href="route('app.products.show', $product)" wire:navigate class="min-w-0 flex-1 !h-auto !items-center gap-3 py-2">
                        <x-product-thumb :product="$product" thumbnail size="size-10" @class(['opacity-50 grayscale' => $item['crossedOff']]) />
                        <div class="grid min-w-0 gap-0.5">
                            <flux:text @class(['truncate font-medium', 'text-zinc-500 line-through' => $item['crossedOff']])>{{ $product->title }}</flux:text>
                            <flux:text size="sm" class="truncate text-zinc-500">
                                @if ($item['shop'] === null)
                                    {{ __('No shop sells this now') }}
                                @else
                                    {{ $item['headline']->text() }} · {{ $item['shop']->host }}
                                @endif
                            </flux:text>
                        </div>
                    </flux:menu.item>
                    <flux:button
                        size="xs"
                        variant="ghost"
                        icon="x-mark"
                        class="me-1 shrink-0"
                        wire:click="remove('{{ $product->id }}')"
                        aria-label="{{ __('Remove :title from shopping list', ['title' => $product->title]) }}"
                        data-test="shopping-menu-remove"
                    />
                </div>
            @endforeach

            @php($openShown = count(array_filter($items, fn (array $item): bool => ! $item['crossedOff'])))
            @if ($openCount > $openShown)
                <flux:text size="sm" class="px-3 py-1 text-zinc-500">{{ __('and :count more', ['count' => $openCount - $openShown]) }}</flux:text>
            @endif
        @endif

        <flux:menu.separator />
        <flux:menu.item icon:trailing="arrow-right" :href="route('app.shopping-list')" wire:navigate data-test="shopping-list-open">{{ __('Open shopping list') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
