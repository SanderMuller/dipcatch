{{-- Polls so a drop that fires while the page is open appears without a reload. --}}
<flux:dropdown position="bottom" align="end" wire:poll.30s>
    <flux:button variant="ghost" size="sm" icon="bell" class="relative" aria-label="{{ __('Notifications') }}">
        @if ($unreadCount > 0)
            <flux:badge color="red" size="sm" class="absolute -end-1 -top-1">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</flux:badge>
        @endif
    </flux:button>

    <flux:menu class="w-80">
        <div class="flex items-center justify-between px-2 py-1.5">
            <flux:heading size="sm">{{ __('Notifications') }}</flux:heading>
            @if ($unreadCount > 0)
                <flux:button variant="ghost" size="xs" wire:click="markAllAsRead">{{ __('Mark all read') }}</flux:button>
            @endif
        </div>

        <flux:menu.separator />

        @forelse ($items as $item)
            <flux:menu.item
                :href="$item['url']"
                wire:click="markAsRead('{{ $item['id'] }}')"
                class="!h-auto !items-start gap-2 py-2"
            >
                <div class="grid gap-0.5">
                    <div class="flex items-start gap-2">
                        @if ($item['unread'])
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-red-500"></span>
                        @endif
                        <flux:text class="font-medium">{{ $item['title'] }}</flux:text>
                    </div>

                    @if ($item['price'] !== null)
                        <flux:text size="sm" class="text-zinc-500">
                            {{ \App\Support\MoneyFormatter::format($item['price'], $item['currency'] ?? 'EUR') }}
                            @if ($item['host'] !== null) · {{ $item['host'] }} @endif
                        </flux:text>
                        @if ($item['bundleQuantity'] !== null && $item['bundleTotalPrice'] !== null)
                            <flux:text size="sm" class="text-zinc-500">
                                {{ __(':quantity for :total', ['quantity' => $item['bundleQuantity'], 'total' => \App\Support\MoneyFormatter::format($item['bundleTotalPrice'], $item['currency'] ?? 'EUR')]) }}
                                @if ($item['singleItemPrice'] !== null) · {{ __('or :price each', ['price' => \App\Support\MoneyFormatter::format($item['singleItemPrice'], $item['currency'] ?? 'EUR')]) }} @endif
                            </flux:text>
                        @endif
                    @elseif ($item['body'] !== null)
                        <flux:text size="sm" class="text-zinc-500">{{ $item['body'] }}</flux:text>
                    @endif

                    <flux:text size="sm" class="text-zinc-400">{{ $item['at']?->diffForHumans() }}</flux:text>
                </div>
            </flux:menu.item>
        @empty
            <div class="px-3 py-6 text-center">
                <flux:text class="text-zinc-500">{{ __('Nothing yet.') }}</flux:text>
                <flux:text size="sm" class="text-zinc-400">{{ __('Price drops show up here as they happen.') }}</flux:text>
            </div>
        @endforelse
    </flux:menu>
</flux:dropdown>
