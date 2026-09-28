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
        @elseif ($openCount === 0)
            <div class="px-3 py-6 text-center">
                <flux:text class="text-zinc-500">{{ __('Everything is crossed off.') }}</flux:text>
            </div>
        @else
            @foreach ($items as $item)
                <flux:menu.item :href="route('app.products.show', $item['product'])" wire:navigate class="!h-auto !items-start py-2" wire:key="shopping-menu-{{ $item['product']->id }}">
                    <div class="grid min-w-0 gap-0.5">
                        <flux:text class="truncate font-medium">{{ $item['product']->title }}</flux:text>
                        <flux:text size="sm" class="truncate text-zinc-500">
                            @if ($item['shop'] === null)
                                {{ __('No shop sells this now') }}
                            @else
                                {{ $item['headline']->text() }} · {{ $item['shop']->host }}
                            @endif
                        </flux:text>
                    </div>
                </flux:menu.item>
            @endforeach

            @if ($openCount > count($items))
                <flux:text size="sm" class="px-3 py-1 text-zinc-500">{{ __('and :count more', ['count' => $openCount - count($items)]) }}</flux:text>
            @endif
        @endif

        <flux:menu.separator />
        <flux:menu.item icon="arrow-right" :href="route('app.shopping-list')" wire:navigate data-test="shopping-list-open">{{ __('Open shopping list') }}</flux:menu.item>
    </flux:menu>
</flux:dropdown>
