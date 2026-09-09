<div>
    <flux:heading size="xl">{{ __('Connections') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500">{{ __('Applications you have connected to your DipCatch account.') }}</flux:text>

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('MCP endpoint') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-500">{{ __('Point an MCP client at this URL and authorise it with your account.') }}</flux:text>
        <flux:input class="mt-3" readonly value="{{ $endpoint }}" copyable />
    </flux:card>

    <flux:card class="mt-6">
        <flux:heading size="lg">{{ __('Connected applications') }}</flux:heading>

        <div class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
            @forelse ($connections as $connection)
                <div class="flex items-center justify-between gap-3 py-3" wire:key="token-{{ $connection['id'] }}">
                    <div>
                        <flux:text class="font-medium">{{ $connection['name'] }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500">
                            @if ($connection['granted']) {{ __('Granted :date', ['date' => $connection['granted']]) }} @endif
                            @if ($connection['expires']) · {{ __('Expires :date', ['date' => $connection['expires']]) }} @endif
                        </flux:text>
                    </div>

                    <flux:button
                        size="sm"
                        variant="danger"
                        wire:click="revoke('{{ $connection['id'] }}')"
                        wire:confirm="{{ __('Disconnect this application?') }}"
                    >
                        {{ __('Disconnect') }}
                    </flux:button>
                </div>
            @empty
                <flux:text class="block py-6 text-center text-zinc-500">{{ __('Nothing connected yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>
</div>
