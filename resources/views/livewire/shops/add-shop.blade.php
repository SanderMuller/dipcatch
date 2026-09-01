@php
    $inputClass = 'block w-full rounded-lg bg-white px-3 py-2 text-sm text-gray-900 shadow-xs ring-1 ring-gray-300 transition focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-70 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:placeholder:text-gray-500';
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
        @include('livewire.shops.partials.manual-selector')
    @endif

    @if ($state === 'variant_chooser' && $variants !== null)
        @include('livewire.shops.partials.variant-chooser')
    @endif

    @if ($state === 'preview' && $snapshot !== null)
        @php
            $previewPackSize = $this->snapshotPackSize();
            $previewUnitPrice = null;
            if ($previewPackSize !== null && is_string($snapshot['price'] ?? null)) {
                $previewUnitPriceValue = $previewPackSize->unitPriceFor($snapshot['price']);
                if ($previewUnitPriceValue !== null) {
                    $previewUnitPrice = \App\Support\MoneyFormatter::format($previewUnitPriceValue, $snapshot['currency']) . ' ' . $previewPackSize->label();
                }
            }
        @endphp
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
            <div class="flex items-start gap-3">
                @if (! empty($snapshot['image_url']))
                    <img src="{{ $snapshot['image_url'] }}" alt="" class="h-20 w-20 object-cover rounded" />
                @endif
                <div class="flex-1">
                    <div class="flex items-center gap-1.5 text-sm text-zinc-500">
                        <img src="{{ \App\Support\Favicon::url($host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                        {{ $host }}
                    </div>
                    <div class="font-medium">{{ $snapshot['title'] }}</div>
                    <div class="text-lg font-semibold mt-1 tabular-nums">
                        {{ $snapshot['currency'] }} {{ $snapshot['price'] }}
                        @if (! $snapshot['in_stock'])
                            <span class="ml-2 inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Out of stock</span>
                        @endif
                    </div>
                    @if ($previewUnitPrice !== null)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 tabular-nums">{{ $previewUnitPrice }}</p>
                    @endif
                    @if ($adapterKey === 'user-selector')
                        <p class="mt-1 text-xs text-zinc-500">Extracted via manual selector.</p>
                    @endif
                    @if ($adapterKey === 'checkjebon')
                        <p class="mt-1 text-xs text-zinc-500">Daily price via checkjebon.nl. No product image available.</p>
                    @endif
                </div>
            </div>

            <div class="flex gap-2">
                <button type="button" wire:click="confirm" class="{{ $primaryBtn }}">
                    Confirm: same product
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
                    @case('not_in_dataset')
                        @php $njReason = $errorContext['reason'] ?? null; @endphp
                        @if ($njReason === 'unrecognized_url')
                            No product id found in that URL. Paste a product page URL for this shop (not a category or search page).
                        @elseif ($njReason === 'dataset_empty')
                            The daily price dataset has not been loaded yet. Run <code>php artisan dipcatch:refresh-checkjebon</code> once.
                        @else
                            This product is not in the daily price dataset (checkjebon.nl). DipCatch can only track dataset-listed products for this shop.
                        @endif
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
