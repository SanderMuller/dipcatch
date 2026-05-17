@php
    $inputClass = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-70 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500';
    $labelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $primaryBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
    $ghostBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
@endphp

<div class="space-y-4">
    @if ($state === 'idle' || $state === 'error')
        <form wire:submit.prevent="probe" class="space-y-3">
            <div>
                <label for="add-shop-url" class="{{ $labelClass }}">Add another shop</label>
                <input
                    id="add-shop-url"
                    type="url"
                    wire:model="url"
                    placeholder="https://shop.example.com/product/123"
                    required
                    class="mt-1 {{ $inputClass }}"
                />
                <p class="{{ $helpClass }}">Paste a product URL. We'll fetch the price and show a preview before saving.</p>
            </div>
            <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="probe">Check price</span>
                <span wire:loading wire:target="probe">Checking…</span>
            </button>
        </form>
    @endif

    @if ($state === 'manual_selector')
        <div class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/30">
            <div class="text-sm">
                <strong class="font-semibold text-amber-900 dark:text-amber-100">Auto-detect failed.</strong>
                <span class="text-amber-800 dark:text-amber-200">
                    We couldn't find the price on that page. Paste the CSS selector for the price element below.
                </span>
            </div>

            <form wire:submit.prevent="probeWithSelectors" class="space-y-3">
                <div>
                    <label for="price-selector" class="{{ $labelClass }}">Price selector <span class="text-red-600">*</span></label>
                    <input
                        id="price-selector"
                        type="text"
                        wire:model="priceSelector"
                        placeholder=".product-price__amount"
                        required
                        class="mt-1 {{ $inputClass }} font-mono"
                    />
                    <p class="{{ $helpClass }}">Right-click the price on the page → Inspect → copy the matching CSS selector.</p>
                </div>

                <div>
                    <label for="manual-currency" class="{{ $labelClass }}">Currency</label>
                    <select
                        id="manual-currency"
                        wire:model="manualCurrency"
                        class="mt-1 {{ $inputClass }}"
                    >
                        @foreach (\App\Support\Iso4217::CODES as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="title-selector" class="{{ $labelClass }}">Title selector <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input
                        id="title-selector"
                        type="text"
                        wire:model="titleSelector"
                        placeholder="h1.product-name"
                        class="mt-1 {{ $inputClass }} font-mono"
                    />
                </div>

                <div>
                    <label for="image-selector" class="{{ $labelClass }}">Image selector <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input
                        id="image-selector"
                        type="text"
                        wire:model="imageSelector"
                        placeholder="img.product-image"
                        class="mt-1 {{ $inputClass }} font-mono"
                    />
                    <p class="{{ $helpClass }}">Leave empty to use the page's OpenGraph image.</p>
                </div>

                @if ($errorCode === 'user_selector_required')
                    <p class="text-xs text-red-600">Price selector is required.</p>
                @elseif ($errorCode === 'user_selector_invalid')
                    <p class="text-xs text-red-600">That CSS selector isn't valid syntax.</p>
                @elseif ($errorCode === 'user_selector_no_match')
                    <p class="text-xs text-red-600">No element on the page matches that selector.</p>
                @elseif ($errorCode === 'user_selector_no_price')
                    <p class="text-xs text-red-600">The matched element doesn't contain a parseable price.</p>
                @endif

                <div class="flex gap-2">
                    <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="probeWithSelectors">Try selector</span>
                        <span wire:loading wire:target="probeWithSelectors">Fetching…</span>
                    </button>
                    <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($state === 'variant_chooser' && $variants !== null)
        <div class="space-y-3 rounded-lg border border-blue-300 bg-blue-50 p-4 dark:border-blue-700 dark:bg-blue-950/30">
            <div class="text-sm">
                <strong class="font-semibold text-blue-900 dark:text-blue-100">Multiple variants on this page.</strong>
                <span class="text-blue-800 dark:text-blue-200">
                    Pick the one to track:
                </span>
            </div>

            <form wire:submit.prevent="selectVariant" class="space-y-3">
                <div class="space-y-2">
                    @foreach ($variants as $variant)
                        <label class="flex items-center gap-3 rounded-md border border-blue-200 bg-white px-3 py-2 cursor-pointer hover:bg-blue-50 dark:border-blue-700 dark:bg-blue-950/40 dark:hover:bg-blue-900/40">
                            <input
                                type="radio"
                                wire:model="chosenVariantKey"
                                value="{{ $variant['key'] }}"
                                class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300"
                            />
                            <div class="flex-1">
                                <div class="font-medium text-sm">{{ $variant['title'] }}</div>
                                <div class="text-xs text-zinc-500 truncate">{{ $variant['key'] }}</div>
                            </div>
                            <div class="text-sm font-semibold whitespace-nowrap">
                                {{ $variant['currency'] }} {{ $variant['price'] }}
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="selectVariant">Use this variant</span>
                        <span wire:loading wire:target="selectVariant">Fetching…</span>
                    </button>
                    <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    @if ($state === 'preview' && $snapshot !== null)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
            <div class="flex items-start gap-3">
                @if (! empty($snapshot['image_url']))
                    <img src="{{ $snapshot['image_url'] }}" alt="" class="h-20 w-20 object-cover rounded" />
                @endif
                <div class="flex-1">
                    <div class="text-sm text-zinc-500">{{ $host }}</div>
                    <div class="font-medium">{{ $snapshot['title'] }}</div>
                    <div class="text-lg font-semibold mt-1">
                        {{ $snapshot['currency'] }} {{ $snapshot['price'] }}
                        @if (! $snapshot['in_stock'])
                            <span class="ml-2 inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Out of stock</span>
                        @endif
                    </div>
                    @if ($adapterKey === 'user-selector')
                        <p class="mt-1 text-xs text-zinc-500">Extracted via manual selector.</p>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <button type="button" wire:click="confirm" class="{{ $primaryBtn }}">
                    Confirm — same product
                </button>
                <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">
                    Different product
                </button>
            </div>
        </div>
    @endif

    @if ($state === 'error')
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-700 dark:bg-red-950/30">
            <div class="text-sm font-semibold text-red-900 dark:text-red-100">Could not add this shop</div>
            <p class="mt-1 text-sm text-red-800 dark:text-red-200">
                @switch($errorCode)
                    @case('invalid_url')
                        That doesn't look like a valid URL.
                        @break
                    @case('empty_url')
                        Paste a product URL above.
                        @break
                    @case('duplicate')
                        @php $dupHost = $errorContext['existing_shop_host'] ?? null; @endphp
                        This URL is already tracked for this product{{ $dupHost ? ' (' . $dupHost . ')' : '' }}.
                        @break
                    @case('robots_disallowed')
                        This shop's robots.txt forbids automated access.
                        @break
                    @case('blocked')
                        This shop is blocking automated checks (Cloudflare/Akamai). We can't track it right now.
                        @break
                    @case('host_rate_limited')
                        This shop returned a rate-limit response (HTTP 429). Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('local_throttle')
                        We're spacing out checks to this shop to be polite. Try again in {{ $errorContext['retry_after_seconds'] ?? '~60' }} seconds.
                        @break
                    @case('probe_rate_limited')
                        You've probed too many URLs in the last minute. Slow down a bit.
                        @break
                    @case('temporary_failure')
                        The shop is having a server problem (HTTP {{ $errorContext['status'] ?? '5xx' }}). Try again later.
                        @break
                    @case('http_error')
                        The shop returned HTTP {{ $errorContext['status'] ?? 'error' }}. Check the URL and try again.
                        @break
                    @case('currency_mismatch')
                        That shop sells in {{ $errorContext['actual'] ?? '?' }} but this product is tracked in {{ $errorContext['expected'] ?? '?' }}. Multi-currency tracking is not supported yet.
                        @break
                    @default
                        {{ $errorCode }}
                @endswitch
            </p>
            <div class="mt-2">
                <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">Try a different URL</button>
            </div>
        </div>
    @endif
</div>
