@php
    $inputClass = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:opacity-70 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500';
    $labelClass = 'block text-sm font-medium text-gray-700 dark:text-gray-200';
    $helpClass = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
    $primaryBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
    $ghostBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
@endphp

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
