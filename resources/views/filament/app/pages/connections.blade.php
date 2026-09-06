<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Connected apps</x-slot>
        <x-slot name="description">Assistants and tools you have given access to your DipCatch account.</x-slot>

        @if ($this->connections() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing is connected. Add DipCatch as a connector in your assistant using the address below, and you will be asked to sign in and approve.
            </p>
        @else
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($this->connections() as $connection)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                        <div>
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $connection['name'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                Connected {{ $connection['granted'] ?? 'recently' }}@if ($connection['expires']) &middot; expires {{ $connection['expires'] }}@endif
                            </p>
                        </div>

                        <x-filament::button
                            color="danger"
                            size="sm"
                            wire:click="revoke('{{ $connection['id'] }}')"
                            wire:confirm="Disconnect {{ $connection['name'] }}? It will lose access straight away."
                        >
                            Disconnect
                        </x-filament::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Address</x-slot>
        <x-slot name="description">Paste this into your assistant when it asks for a custom connector.</x-slot>

        <pre class="overflow-x-auto rounded-xl bg-gray-50 p-4 text-sm dark:bg-white/5"><code>{{ $this->endpoint() }}</code></pre>
    </x-filament::section>
</x-filament-panels::page>
