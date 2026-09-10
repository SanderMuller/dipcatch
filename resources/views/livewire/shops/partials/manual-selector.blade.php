<flux:callout variant="warning" icon="exclamation-triangle">
    <flux:callout.heading>Auto-detect failed.</flux:callout.heading>
    <flux:callout.text>
        We couldn't find the price on that page. Paste the CSS selector for the price element below.
    </flux:callout.text>

    <form wire:submit.prevent="probeWithSelectors" class="mt-4 space-y-3">
        <flux:input
            id="price-selector"
            wire:model="priceSelector"
            :label="__('Price selector')"
            :description="__('Right-click the price on the page → Inspect → copy the matching CSS selector.')"
            placeholder=".product-price__amount"
            required
            class="font-mono"
        />

        <flux:select
            id="manual-currency"
            wire:model="manualCurrency"
            variant="listbox"
            searchable
            :label="__('Currency')"
        >
            @foreach (\App\Support\Iso4217::CODES as $code)
                <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            id="title-selector"
            wire:model="titleSelector"
            :label="__('Title selector')"
            :description="__('Optional')"
            placeholder="h1.product-name"
            class="font-mono"
        />

        <flux:input
            id="image-selector"
            wire:model="imageSelector"
            :label="__('Image selector')"
            :description="__('Leave empty to use the page\'s OpenGraph image.')"
            placeholder="img.product-image"
            class="font-mono"
        />

        @if ($errorCode === 'user_selector_required')
            <flux:text class="text-red-600">Price selector is required.</flux:text>
        @elseif ($errorCode === 'user_selector_invalid')
            <flux:text class="text-red-600">That CSS selector isn't valid syntax.</flux:text>
        @elseif ($errorCode === 'user_selector_no_match')
            <flux:text class="text-red-600">No element on the page matches that selector.</flux:text>
        @elseif ($errorCode === 'user_selector_no_price')
            <flux:text class="text-red-600">The matched element doesn't contain a parseable price.</flux:text>
        @endif

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="probeWithSelectors">Try selector</span>
                <span wire:loading wire:target="probeWithSelectors">Fetching…</span>
            </flux:button>
            <flux:button type="button" wire:click="cancel">Cancel</flux:button>
        </div>
    </form>
</flux:callout>
