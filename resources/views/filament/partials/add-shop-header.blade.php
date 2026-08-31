@php
    /** @var \App\Models\Product $product */
@endphp

{{--
    Inline replacement for the old "Add a shop" action modal. Hosting the
    AddShop Livewire component inside a Filament action modal hits an
    upstream bug family (nested Livewire in modalContent desyncs the
    modal's Alpine state — filamentphp/filament#16549, #15568): the window
    turns invisible while a full-screen close-overlay keeps catching
    clicks, so the modal "just closes". A native <details> disclosure
    needs no modal machinery and no JavaScript at all.
--}}
<details class="group w-full">
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
