@php
    /** @var \App\Models\Product $product */
@endphp

{{--
    Inline replacement for the old "Add a shop" action modal. Hosting the
    AddShop Livewire component inside a Filament action modal hits an
    upstream bug family (nested Livewire in modalContent desyncs the
    modal's Alpine state — filamentphp/filament#16549, #15568): the window
    turns invisible while a full-screen close-overlay keeps catching
    clicks, so the modal "just closes". A native <details> disclosure needs
    no modal machinery; the small Alpine binding below only mirrors its open
    state so one suggestions list shows at a time.
--}}
<div class="w-full space-y-4" x-data="{ addOpen: false }">
    @php($mismatchedGtinHosts = $product->mismatchedGtinHosts())

    @if ($mismatchedGtinHosts !== [])
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
            These shops report different article numbers (EAN): {{ implode(', ', $mismatchedGtinHosts) }}. They may be different pack sizes or products.
        </p>
    @endif

    <div x-show="! addOpen" x-cloak>
        @livewire('suggestions.shop-suggestions', ['product' => $product], key('shop-suggestions-panel-' . $product->id))
    </div>

    <details
        class="group w-full"
        @toggle="addOpen = $el.open"
        @open-add-shop.window="$el.open = true; addOpen = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
    >
        <summary class="ms-auto inline-flex w-fit cursor-pointer list-none items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition select-none hover:bg-primary-500 [&::-webkit-details-marker]:hidden">
        <svg viewBox="0 0 20 20" fill="currentColor" class="size-5 group-open:hidden" aria-hidden="true">
            <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
        </svg>
        <svg viewBox="0 0 20 20" fill="currentColor" class="hidden size-5 group-open:block" aria-hidden="true">
            <path d="M4.75 9.25a.75.75 0 0 0 0 1.5h10.5a.75.75 0 0 0 0-1.5H4.75Z" />
        </svg>
        <span class="group-open:hidden">Add a shop</span>
        <span class="hidden group-open:inline">Hide add shop</span>
        </summary>

        <div class="mt-4 w-full rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
            @livewire('shops.add-shop', ['product' => $product], key('add-shop-inline-' . $product->id))
        </div>
    </details>
</div>
