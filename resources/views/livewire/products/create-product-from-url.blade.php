@php
    $inputClass = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-70 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500';
    $labelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $errorTextClass = 'mt-1 text-xs text-red-600';
    $primaryBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
    $ghostBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
    $manualCreateUrl = \App\Filament\App\Resources\Products\ProductResource::getUrl('create-manual', panel: 'app');
@endphp

<div class="space-y-4 max-w-2xl">
    @if ($state === 'idle' || $state === 'error')
        <form wire:submit.prevent="probe" class="space-y-3">
            <div>
                <label for="create-product-url" class="{{ $labelClass }}">Product URL</label>
                <input
                    id="create-product-url"
                    type="url"
                    wire:model="url"
                    placeholder="https://shop.example.com/product/123"
                    required
                    autofocus
                    class="mt-1 {{ $inputClass }}"
                />
                <p class="{{ $helpClass }}">Paste a product URL. We'll fetch the title, image, and price, and suggest drop thresholds.</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="probe">Fetch product</span>
                    <span wire:loading wire:target="probe">Fetching…</span>
                </button>
                <a href="{{ $manualCreateUrl }}" class="text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    Create manually instead
                </a>
            </div>
        </form>
    @endif

    @if ($state === 'manual_selector')
        @include('livewire.shops.partials.manual-selector')
    @endif

    @if ($state === 'variant_chooser' && $variants !== null)
        @include('livewire.shops.partials.variant-chooser')
    @endif

    @if ($state === 'preview' && $snapshot !== null)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
            @if ($existingTrackedProduct !== null)
                <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200">
                    This URL is already tracked on
                    <a
                        href="{{ \App\Filament\App\Resources\Products\ProductResource::getUrl('view', ['record' => $existingTrackedProduct['id']], panel: 'app') }}"
                        class="font-semibold underline"
                    >{{ $existingTrackedProduct['title'] }}</a>.
                    You can still create a separate product for it.
                </div>
            @endif

            <div class="flex items-start gap-3">
                @if (! empty($snapshot['image_url']))
                    <img src="{{ $snapshot['image_url'] }}" alt="" class="h-20 w-20 object-cover rounded" />
                @endif
                <div class="flex-1">
                    <div class="text-sm text-zinc-500">{{ $host }}</div>
                    <div class="text-lg font-semibold mt-1">
                        {{ $snapshot['currency'] }} {{ $snapshot['price'] }}
                        @if (! $snapshot['in_stock'])
                            <span class="ml-2 inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Out of stock</span>
                        @endif
                    </div>
                    @if ($adapterKey === 'user-selector')
                        <p class="mt-1 text-xs text-zinc-500">Extracted via manual selector.</p>
                    @endif
                    @if ($adapterKey === 'checkjebon')
                        <p class="mt-1 text-xs text-zinc-500">Daily price via checkjebon.nl — no product image available.</p>
                    @endif
                </div>
            </div>

            <form wire:submit.prevent="confirm" class="space-y-3">
                <div>
                    <label for="create-product-title" class="{{ $labelClass }}">Title <span class="text-red-600">*</span></label>
                    <input
                        id="create-product-title"
                        type="text"
                        wire:model="title"
                        required
                        class="mt-1 {{ $inputClass }}"
                    />
                    @error('title') <p class="{{ $errorTextClass }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="create-product-image-url" class="{{ $labelClass }}">Image URL <span class="text-gray-400 font-normal">(optional)</span></label>
                    <input
                        id="create-product-image-url"
                        type="url"
                        wire:model="imageUrl"
                        class="mt-1 {{ $inputClass }}"
                    />
                    @error('imageUrl') <p class="{{ $errorTextClass }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="create-product-threshold-pct" class="{{ $labelClass }}">Drop threshold (%)</label>
                        <input
                            id="create-product-threshold-pct"
                            type="number"
                            step="0.01"
                            min="0.01"
                            max="99.99"
                            wire:model="thresholdPct"
                            class="mt-1 {{ $inputClass }}"
                        />
                        @error('thresholdPct') <p class="{{ $errorTextClass }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="create-product-threshold-abs" class="{{ $labelClass }}">Drop threshold ({{ $snapshot['currency'] }})</label>
                        <input
                            id="create-product-threshold-abs"
                            type="number"
                            step="0.01"
                            min="0.01"
                            wire:model="thresholdAbs"
                            class="mt-1 {{ $inputClass }}"
                        />
                        @error('thresholdAbs') <p class="{{ $errorTextClass }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="{{ $helpClass }}">Suggested from the price — you get notified when the price drops past either threshold.</p>

                <div class="flex gap-2">
                    <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="confirm">Create product</span>
                        <span wire:loading wire:target="confirm">Creating…</span>
                    </button>
                    <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">Start over</button>
                </div>
            </form>
        </div>
    @endif

    @if ($state === 'error')
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 dark:border-red-700 dark:bg-red-950/30">
            <div class="text-sm font-semibold text-red-900 dark:text-red-100">Could not fetch that page</div>
            <p class="mt-1 text-sm text-red-800 dark:text-red-200">
                @switch($errorCode)
                    @case('invalid_url')
                        That doesn't look like a valid URL.
                        @break
                    @case('empty_url')
                        Paste a product URL above.
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
                    @case('not_in_dataset')
                        @php $njReason = $errorContext['reason'] ?? null; @endphp
                        @if ($njReason === 'use_boodschaapje')
                            Lidl prices come via boodschaapje.nl. Find the product on <a href="https://boodschaapje.nl" target="_blank" rel="noopener" class="font-semibold underline">boodschaapje.nl</a> and paste that URL instead.
                        @elseif ($njReason === 'unrecognized_url')
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
            <p class="mt-2 text-sm text-red-800 dark:text-red-200">
                Can't reach the page? <a href="{{ $manualCreateUrl }}" class="font-semibold underline">Create the product manually</a> instead.
            </p>
        </div>
    @endif
</div>
