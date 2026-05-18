@php
    /** @var \App\Models\Product|null $product */
    $shared = $product?->isPubliclyShared() === true;
    $url = $shared ? $product->publicShareUrl() : null;

    $inputClass = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white';
    $primaryBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
    $warningBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-amber-400 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2';
    $dangerBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2';
    $ghostBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
@endphp

<div class="space-y-4">
    @if ($shared)
        <div x-data="{ copied: false }" class="space-y-2">
            <label for="public-share-url" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                Public URL
            </label>
            <div class="flex gap-2">
                <input
                    id="public-share-url"
                    type="text"
                    readonly
                    value="{{ $url }}"
                    x-ref="urlInput"
                    x-on:focus="$event.target.select()"
                    class="{{ $inputClass }} font-mono"
                />
                <button
                    type="button"
                    x-on:click="
                        navigator.clipboard.writeText($refs.urlInput.value).then(() => {
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        })
                    "
                    class="{{ $ghostBtn }} shrink-0"
                >
                    <span x-show="!copied" x-cloak>Copy</span>
                    <span x-show="copied" x-cloak>Copied!</span>
                </button>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Anyone with this link can view the product. Existing previews may persist for some time after you stop sharing.
            </p>
        </div>

        <div class="flex flex-wrap gap-2 pt-3 border-t border-gray-200 dark:border-white/10">
            <button
                type="button"
                wire:click="rotateShareLink"
                wire:loading.attr="disabled"
                wire:confirm="Generate a new URL? The current one stops working immediately."
                class="{{ $warningBtn }}"
            >
                <span wire:loading.remove wire:target="rotateShareLink">Rotate link</span>
                <span wire:loading wire:target="rotateShareLink">Rotating…</span>
            </button>
            <button
                type="button"
                wire:click="stopSharing"
                wire:loading.attr="disabled"
                wire:confirm="Stop sharing this product? The public link will 404."
                class="{{ $dangerBtn }}"
            >
                <span wire:loading.remove wire:target="stopSharing">Stop sharing</span>
                <span wire:loading wire:target="stopSharing">Stopping…</span>
            </button>
        </div>
    @else
        <p class="text-sm text-gray-700 dark:text-gray-200">
            No public link yet. Generate one to share the product summary, price history, and shop list with anyone.
        </p>
        <button
            type="button"
            wire:click="generateShareLink"
            wire:loading.attr="disabled"
            class="{{ $primaryBtn }}"
        >
            <span wire:loading.remove wire:target="generateShareLink">Generate public link</span>
            <span wire:loading wire:target="generateShareLink">Generating…</span>
        </button>
    @endif
</div>
