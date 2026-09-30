{{-- `.visible`: the copy inside the closed add-shop form does not poll. --}}
<div x-data="{ hideHost: '', hideLabel: '' }" @if ($pollSeconds !== null) wire:poll.visible.{{ $pollSeconds }}s @endif>
    @php($total = count($suggestions) + $webSuggestions->count())

    {{-- Present from the first render, so a screen reader hears the search
         start and end; a region added with its text is not announced. --}}
    <p role="status" class="sr-only" data-test="web-discovery-status">
        @if ($discovering)
            {{ __('Looking for more shops…') }}
        @elseif ($webSuggestions->isNotEmpty())
            {{ trans_choice('Found :count more shop on the web.|Found :count more shops on the web.', $webSuggestions->count(), ['count' => $webSuggestions->count()]) }}
        @endif
    </p>

    @if ($explainEmpty && $total === 0 && ! $discovering && $datasetIsUsable)
        <flux:text size="sm" class="text-zinc-500">
            No shop suggestions for this product right now.
        </flux:text>
    @endif

    @if ($total > 0)
        {{--
            A disclosure rather than an open panel: these are shops the user
            has not chosen, and open they push the shops actually tracked —
            and the price history above them — off the first screen. The
            count keeps them discoverable while closed.
        --}}
        <flux:accordion variant="reverse">
            <flux:accordion.item :expanded="$expanded">
                <flux:accordion.heading>
                    Also sold at
                    <flux:badge size="sm" class="ms-2">{{ $total }}</flux:badge>
                </flux:accordion.heading>
                <flux:accordion.content>
            @if ($suggestions !== [])
            <flux:text size="sm" class="text-zinc-500">
                Matched on name and pack size. These prices come from a daily list. DipCatch checks the shop itself once you add it.
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
                            {{-- Per unit first when the dataset names a size, as everywhere
                                 else a price is compared. The dataset is in euros. --}}
                            @php($suggestionSize = \App\Support\PackSize::resolve($suggestion->size, false, $suggestion->name))
                            @php($suggestionUnitPrice = $suggestionSize?->unitPriceFor($suggestion->price))
                            <flux:text size="sm" class="text-zinc-500 tabular-nums" data-test="suggestion-price">
                                @if ($suggestionUnitPrice !== null)
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ \App\Support\MoneyFormatter::unitPrice($suggestionUnitPrice, 'EUR') }} {{ $suggestionSize->label() }}</span> ·
                                @endif
                                <span title="Regular price from the daily dataset">{{ __('dataset price :price', ['price' => \App\Support\PackLine::format($suggestion->price, 'EUR', $suggestionSize)]) }}</span>
                                @unless ($suggestion->trackable)
                                    · <span class="text-amber-600 dark:text-amber-400">not trackable yet</span>
                                @endunless
                                @if ($suggestion->checked)
                                    · <span class="text-savings-strong" data-test="suggestion-checked">{{ __('same product, checked by AI') }}</span>
                                @endif
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
                                    x-on:click="$el.dataset.adding = 'true'"
                                    x-on:shop-probe-finished.window="delete $el.dataset.adding"
                                    class="data-adding:pointer-events-none"
                                >
                                    {{-- The click only hands the link to the add-shop form,
                                         which then reads the shop's page. Say so on the
                                         button at once, so it never looks like nothing
                                         happened. --}}
                                    <span class="in-data-adding:hidden">Add</span>
                                    <span class="hidden items-center gap-1 in-data-adding:inline-flex"><flux:icon.loading class="size-3" /> Adding…</span>
                                </flux:button>
                            @else
                                <flux:button size="xs" disabled title="This shop cannot be price-checked yet.">
                                    Add
                                </flux:button>
                            @endif

                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="xs" variant="ghost" icon:trailing="chevron-down" data-test="suggestion-hide-menu">
                                    {{ __('Hide') }}<span class="sr-only"> {{ $suggestion->chainLabel }}</span>
                                </flux:button>
                                <flux:menu>
                                    <flux:menu.item icon="x-mark" wire:click="dismiss({{ \Illuminate\Support\Js::from($suggestion->chain) }}, {{ \Illuminate\Support\Js::from($suggestion->externalId) }})" data-test="suggestion-hide">
                                        {{ __('Hide for this product') }}
                                    </flux:menu.item>
                                    <flux:menu.item icon="eye-slash" x-on:click="hideHost = {{ \Illuminate\Support\Js::from($host) }}; hideLabel = {{ \Illuminate\Support\Js::from(\App\Models\HiddenShop::displayName($suggestion->chainLabel)) }}; $flux.modal({{ \Illuminate\Support\Js::from('hide-shop-' . $this->getId()) }}).show()" data-test="suggestion-hide-shop">
                                        {{ __('Don’t suggest :shop', ['shop' => \App\Models\HiddenShop::displayName($suggestion->chainLabel)]) }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </li>
                @endforeach
            </ul>
            @endif

            @if ($webSuggestions->isNotEmpty())
                <flux:text size="sm" @class(['text-zinc-500 dark:text-zinc-400', 'mt-4' => $suggestions !== []])>
                    {{ __('Found on the web and checked by AI against the page itself. DipCatch checks the price again once you add the shop.') }}
                </flux:text>

                <ul class="mt-3 divide-y divide-zinc-100 dark:divide-white/5" data-test="web-suggestions">
                    @foreach ($webSuggestions as $finding)
                        @php($webHost = $finding->addHost())
                        @php($webSize = $finding->page_pack_quantity !== null && $finding->page_pack_unit !== null ? \App\Support\PackSize::of((float) $finding->page_pack_quantity, $finding->page_pack_unit) : null)
                        @php($webCurrency = $finding->page_currency ?? 'EUR')
                        @php($webUnitPrice = $finding->page_price !== null ? $webSize?->unitPriceFor($finding->page_price) : null)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5" wire:key="web-suggestion-{{ $finding->id }}" data-test="web-suggestion">
                            <img src="{{ \App\Support\Favicon::url($webHost) }}" alt="" loading="lazy" class="size-5 shrink-0 rounded" />

                            <div class="min-w-0 flex-1">
                                <flux:text class="truncate font-medium">
                                    {{ $webHost }}
                                    <span class="font-normal text-zinc-500 dark:text-zinc-400">— {{ $finding->page_title ?? $finding->search_title }}</span>
                                </flux:text>
                                <flux:text size="sm" class="text-zinc-500 tabular-nums dark:text-zinc-400" data-test="web-suggestion-price">
                                    @if ($webUnitPrice !== null)
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ \App\Support\MoneyFormatter::unitPrice($webUnitPrice, $webCurrency) }} {{ $webSize->label() }}</span> ·
                                    @endif
                                    @if ($finding->page_price !== null)
                                        {{ __(':price when checked on :date', ['price' => \App\Support\PackLine::format($finding->page_price, $webCurrency, $webSize), 'date' => ($finding->read_at ?? $finding->checked_at)?->isoFormat('D MMM')]) }} ·
                                    @endif
                                    {{-- A barcode match skips the second AI check, so it says so. --}}
                                    <span class="text-savings-strong">{{ $finding->matched_gtin !== null ? __('same barcode as your product') : __('same product, checked by AI') }}</span>
                                </flux:text>
                            </div>

                            <div class="flex w-full shrink-0 items-center gap-2 pl-8 sm:w-auto sm:pl-0">
                                <flux:button size="xs" :href="$finding->add_url ?? $finding->url" target="_blank" rel="noopener noreferrer">
                                    Open<span class="sr-only"> {{ $webHost }} {{ __('(opens in a new tab)') }}</span>
                                </flux:button>

                                <flux:button
                                    size="xs"
                                    variant="primary"
                                    wire:click="accept({{ \Illuminate\Support\Js::from($finding->add_url ?? $finding->url) }})"
                                    wire:loading.attr="disabled"
                                    x-on:click="$el.dataset.adding = 'true'"
                                    x-on:shop-probe-finished.window="delete $el.dataset.adding"
                                    class="data-adding:pointer-events-none"
                                    data-test="web-suggestion-add"
                                >
                                    <span class="in-data-adding:hidden">Add</span>
                                    <span class="hidden items-center gap-1 in-data-adding:inline-flex"><flux:icon.loading class="size-3" /> Adding…</span>
                                    <span class="sr-only"> {{ $webHost }}</span>
                                </flux:button>

                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="xs" variant="ghost" icon:trailing="chevron-down" data-test="web-suggestion-hide-menu">
                                        {{ __('Hide') }}<span class="sr-only"> {{ $webHost }}</span>
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.item icon="x-mark" wire:click="dismissWeb({{ $finding->id }})" data-test="web-suggestion-hide">
                                            {{ __('Hide for this product') }}
                                        </flux:menu.item>
                                        <flux:menu.item icon="eye-slash" x-on:click="hideHost = {{ \Illuminate\Support\Js::from($webHost) }}; hideLabel = {{ \Illuminate\Support\Js::from($webHost) }}; $flux.modal({{ \Illuminate\Support\Js::from('hide-shop-' . $this->getId()) }}).show()" data-test="web-suggestion-hide-shop">
                                            {{ __('Don’t suggest :shop', ['shop' => $webHost]) }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
    @endif

    <x-hide-shop-confirm :name="'hide-shop-' . $this->getId()" />

    {{-- Outside the disclosure, so it shows while the disclosure is closed. --}}
    @if ($discovering)
        <flux:text size="sm" @class(['text-zinc-500 dark:text-zinc-400', 'mt-2' => $total > 0]) aria-hidden="true" data-test="web-discovery-running">
            {{ __('Looking for more shops…') }}
        </flux:text>
    @endif
</div>
