@props(['product', 'shareUrl'])

{{-- Each pill states where the product stands and is also the
     control that changes it, so the state and the button
     can never disagree. --}}
<div {{ $attributes->class('flex shrink-0 gap-2') }}>
    <flux:modal.trigger name="sharing">
        <flux:tooltip :content="$shareUrl ? __('Anyone with the link can see this product and its prices. Click to manage the link.') : __('Only you can see this product. Click to create a public link.')">
            <button
                type="button"
                class="relative inline-flex items-center gap-1.5 rounded-full bg-paper py-1 pr-3 pl-2 text-sm font-medium ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                data-test="sharing-status"
            >
                <flux:icon.share variant="micro" :class="$shareUrl ? 'size-4 shrink-0 text-brand' : 'size-4 shrink-0 text-zinc-400'" />
                {{ $shareUrl ? __('Shared') : __('Not shared') }}
                <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
            </button>
        </flux:tooltip>
    </flux:modal.trigger>

    <flux:tooltip :content="$product->active ? __('Pause tracking') : __('Resume tracking')">
        <button
            type="button"
            wire:click="togglePaused"
            class="relative inline-flex items-center gap-1.5 rounded-full bg-paper py-1 pr-3 pl-2 text-sm font-medium ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
            data-test="tracking-toggle"
        >
            <span aria-hidden="true" @class(['size-2 shrink-0 rounded-full', 'bg-savings' => $product->active, 'bg-zinc-400' => ! $product->active])></span>
            {{ $product->active ? __('Tracking') : __('Paused') }}
            <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
        </button>
    </flux:tooltip>
</div>
