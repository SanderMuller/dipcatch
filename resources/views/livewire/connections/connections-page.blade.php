<div>
    <flux:heading size="xl" class="tracking-tight">{{ __('Connections') }}</flux:heading>
    <flux:text class="mt-1 max-w-[56ch] text-pretty text-base text-zinc-600 sm:text-sm dark:text-zinc-400">
        {{ __('Connect Claude or ChatGPT to this account, or disconnect an app you already allowed.') }}
    </flux:text>

    {{-- One surface with dividers, not three cards: Claude, ChatGPT, and Other
         assistants are sibling connect options, so they need separation, not elevation. --}}
    <flux:card class="mt-6 p-0!">
        <div class="@container">
            <dl class="grid divide-y divide-zinc-950/5 @3xl:grid-cols-3 @3xl:divide-x @3xl:divide-y-0 dark:divide-white/10">
                <div class="flex h-full flex-col p-5">
                    <dt class="text-base font-medium text-balance sm:text-sm">{{ __('Claude') }}</dt>
                    <dd class="mt-1 text-pretty text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Opens Claude with DipCatch filled in. Review the URL, add the connector, then allow access.') }}</dd>
                    <dd class="mt-auto pt-4">
                        <flux:button variant="primary" icon:trailing="arrow-top-right-on-square" :href="$claudeInstallUrl" target="_blank" rel="noopener noreferrer">
                            {{ __('Connect Claude') }}
                        </flux:button>
                    </dd>
                </div>

                <div class="flex h-full flex-col p-5">
                    <dt class="text-base font-medium text-balance sm:text-sm">{{ __('ChatGPT') }}</dt>
                    @if (is_string($chatgptPluginUrl))
                        <dd class="mt-1 text-pretty text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Opens the DipCatch plugin in ChatGPT. Connect it there, then allow access.') }}</dd>
                        <dd class="mt-auto pt-4">
                            <flux:button icon:trailing="arrow-top-right-on-square" :href="$chatgptPluginUrl" target="_blank" rel="noopener noreferrer">
                                {{ __('Install in ChatGPT') }}
                            </flux:button>
                        </dd>
                    @else
                        <dd class="mt-1 text-pretty text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('ChatGPT needs DipCatch in its plugin directory. That listing is not live yet. Use Claude or this website until it is.') }}</dd>
                    @endif
                </div>

                <div class="flex h-full flex-col p-5">
                    <dt class="text-base font-medium text-balance sm:text-sm">{{ __('Other assistants') }}</dt>
                    <dd class="mt-1 text-pretty text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Other MCP clients can use this URL and then sign in with this account.') }}</dd>
                    <dd class="mt-auto min-w-0 pt-4">
                        <flux:input readonly copyable name="mcp-endpoint" value="{{ $endpoint }}" :aria-label="__('MCP endpoint')" />
                    </dd>
                </div>
            </dl>
        </div>
    </flux:card>

    <flux:card class="mt-6 p-0!">
        <div class="flex flex-col gap-4 pt-5">
            <div class="px-5">
                <flux:heading size="lg">{{ __('Connected applications') }}</flux:heading>
            </div>

            <ul role="list" class="divide-y divide-zinc-950/5 dark:divide-white/10">
                @forelse ($connections as $connection)
                    <li class="flex items-center justify-between gap-3 px-5 py-3 last:pb-5" wire:key="token-{{ $connection['id'] }}">
                        <div class="min-w-0">
                            <flux:text class="truncate font-medium">{{ $connection['name'] }}</flux:text>
                            <flux:text class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">
                                @if ($connection['granted']) {{ __('Granted :date', ['date' => $connection['granted']]) }} @endif
                                @if ($connection['expires']) · {{ __('Expires :date', ['date' => $connection['expires']]) }} @endif
                            </flux:text>
                        </div>

                        <flux:button
                            class="shrink-0"
                            size="sm"
                            variant="danger"
                            wire:click="revoke('{{ $connection['id'] }}')"
                            wire:confirm="{{ __('Disconnect this application?') }}"
                        >
                            {{ __('Disconnect') }}
                        </flux:button>
                    </li>
                @empty
                    <li class="px-5 pb-5">
                        <flux:text class="py-6 text-center text-base text-zinc-500 sm:text-sm">{{ __('Nothing connected yet.') }}</flux:text>
                    </li>
                @endforelse
            </ul>
        </div>
    </flux:card>
</div>
