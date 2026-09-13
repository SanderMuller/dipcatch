<div>
    @if ($suggestions === [] && $datasetIsUsable)
        <flux:text size="sm" class="text-zinc-500">
            No other shops found for this product.
        </flux:text>
    @endif

    @if ($suggestions !== [])
        {{--
            A disclosure rather than an open panel: these are shops the user
            has not chosen, and open they push the shops actually tracked —
            and the price history above them — off the first screen. The
            count keeps them discoverable while closed.
        --}}
        <flux:accordion>
            <flux:accordion.item>
                <flux:accordion.heading>
                    Also sold at
                    <flux:badge size="sm" class="ms-2">{{ count($suggestions) }}</flux:badge>
                </flux:accordion.heading>
                <flux:accordion.content>
            <flux:text size="sm" class="text-zinc-500">
                Matched on name and pack size. Prices come from the daily dataset. DipCatch fetches the live price when you add the shop.
            </flux:text>

            <ul class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach ($suggestions as $suggestion)
                    @php($host = parse_url($suggestion->url, PHP_URL_HOST) ?: '')
                    <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5" wire:key="suggestion-{{ $suggestion->chain }}-{{ $suggestion->externalId }}">
                        <img
                            src="{{ \App\Support\Favicon::url($host) }}"
                            alt=""
                            loading="lazy"
                            class="size-5 shrink-0 rounded"
                        />

                        <div class="min-w-0 flex-1">
                            <flux:text class="truncate font-medium">
                                {{ $suggestion->chainLabel }}
                                <span class="font-normal text-zinc-500">— {{ $suggestion->name }}</span>
                            </flux:text>
                            <flux:text size="sm" class="text-zinc-500">
                                @if ($suggestion->size)
                                    {{ $suggestion->size }} ·
                                @endif
                                <span title="Regular price from the daily dataset">dataset price € {{ $suggestion->price }}</span>
                                @unless ($suggestion->trackable)
                                    · <span class="text-amber-600 dark:text-amber-400">not trackable yet</span>
                                @endunless
                            </flux:text>
                        </div>

                        <div class="flex w-full shrink-0 items-center gap-2 pl-8 sm:w-auto sm:pl-0">
                            {{-- Every row opens: a shopper may want to see the
                                 product before tracking it, not only when
                                 tracking is impossible. --}}
                            <flux:button size="xs" :href="$suggestion->url" target="_blank" rel="noopener noreferrer">
                                Open
                            </flux:button>

                            @if ($suggestion->trackable)
                                {{-- Blade keeps a component attribute value as a literal
                                     string, so `@js()` would never compile here. An echo
                                     does run, and `e()` passes the Htmlable through. --}}
                                <flux:button
                                    size="xs"
                                    variant="primary"
                                    wire:click="accept({{ \Illuminate\Support\Js::from($suggestion->url) }})"
                                    wire:loading.attr="disabled"
                                >
                                    Add
                                </flux:button>
                            @else
                                <flux:button size="xs" disabled title="This shop cannot be price-checked yet.">
                                    Add
                                </flux:button>
                            @endif

                            <flux:button
                                size="xs"
                                variant="ghost"
                                wire:click="dismiss({{ \Illuminate\Support\Js::from($suggestion->chain) }}, {{ \Illuminate\Support\Js::from($suggestion->externalId) }})"
                            >
                                Hide
                            </flux:button>
                        </div>
                    </li>
                @endforeach
            </ul>
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    @endif
</div>
