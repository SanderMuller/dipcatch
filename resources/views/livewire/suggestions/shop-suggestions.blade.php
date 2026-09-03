@php
    /** @var list<\App\Services\Suggestions\ShopSuggestion> $suggestions */
    $ghostBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
    $primaryBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
@endphp

<div>
    @if ($suggestions === [] && $datasetIsUsable)
        <p class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
            No other shops found for this product.
        </p>
    @endif

    @if ($suggestions !== [])
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Also sold at</h3>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                Matched on name and pack size. Prices come from the daily dataset — the live price is fetched when you add the shop.
            </p>

            <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/5">
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
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">
                                {{ $suggestion->chainLabel }}
                                <span class="font-normal text-gray-500 dark:text-gray-400">— {{ $suggestion->name }}</span>
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($suggestion->size)
                                    {{ $suggestion->size }} ·
                                @endif
                                <span title="Regular price from the daily dataset">dataset price € {{ $suggestion->price }}</span>
                                @unless ($suggestion->trackable)
                                    · <span class="text-amber-600 dark:text-amber-400">not trackable yet</span>
                                @endunless
                            </p>
                        </div>

                        <div class="flex w-full shrink-0 items-center gap-2 pl-8 sm:w-auto sm:pl-0">
                            {{-- Every row opens: a shopper may want to see the
                                 product before tracking it, not only when
                                 tracking is impossible. --}}
                            <a href="{{ $suggestion->url }}" target="_blank" rel="noopener noreferrer" class="{{ $ghostBtn }}">
                                Open
                            </a>

                            @if ($suggestion->trackable)
                                <button
                                    type="button"
                                    class="{{ $primaryBtn }}"
                                    wire:click="accept(@js($suggestion->url))"
                                    wire:loading.attr="disabled"
                                >
                                    Add
                                </button>
                            @else
                                <button type="button" class="{{ $ghostBtn }}" disabled title="This shop cannot be price-checked yet.">
                                    Add
                                </button>
                            @endif

                            <button
                                type="button"
                                class="{{ $ghostBtn }}"
                                wire:click="dismiss(@js($suggestion->chain), @js($suggestion->externalId))"
                            >
                                Hide
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
