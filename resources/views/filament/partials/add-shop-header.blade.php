@php
    /** @var \App\Models\Product $product */
    /** @var int|null $shopLimit Null means the plan sets no ceiling. */
    /** @var bool $canAddShop */
    $shopCount = $product->shops->count();
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
        <flux:callout variant="warning" icon="exclamation-triangle">
            These shops report different article numbers (EAN): {{ implode(', ', $mismatchedGtinHosts) }}. They may be different pack sizes or products.
        </flux:callout>
    @endif

    @if ($canAddShop)
        <div x-show="! addOpen" x-cloak>
            @livewire('suggestions.shop-suggestions', ['product' => $product], key('shop-suggestions-panel-' . $product->id))
        </div>

        <details
            class="group w-full"
            @toggle="addOpen = $el.open"
            @open-add-shop.window="$el.open = true; addOpen = true; $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
        >
            <summary class="flex cursor-pointer list-none items-center justify-end gap-3 rounded-lg select-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-800 [&::-webkit-details-marker]:hidden">
                {{-- Stays visible while the form is open: that is exactly
                     when someone wants to know how much room is left. --}}
                @if ($shopLimit !== null)
                    <flux:text size="sm" class="text-zinc-500">{{ $shopCount }} of {{ $shopLimit }} shops</flux:text>
                @endif

                {{-- Primary while closed, secondary once open: the panel it
                     reveals has its own primary action, and a page should
                     only offer one. --}}
                <span class="inline-flex items-center gap-1.5 rounded-full py-2 pe-3 ps-2 text-sm font-medium shadow-sm bg-zinc-800 text-white group-open:bg-zinc-100 group-open:text-zinc-800 group-open:ring-1 group-open:ring-zinc-300 group-open:shadow-none dark:bg-white dark:text-zinc-900 dark:group-open:bg-white/10 dark:group-open:text-zinc-200 dark:group-open:ring-white/10">
                    <flux:icon.plus class="size-5 group-open:hidden" />
                    <flux:icon.x-mark class="hidden size-5 group-open:block" />
                    <span class="group-open:hidden">Add a shop</span>
                    <span class="hidden group-open:inline">Cancel</span>
                </span>
            </summary>

            <flux:card class="mt-4">
                @livewire('shops.add-shop', ['product' => $product], key('add-shop-inline-' . $product->id))
            </flux:card>
        </details>
    @else
        {{-- No Add button and no suggestions: every one of them would end at
             the same refusal on Confirm, after a live fetch of the page.
             Nothing already tracked is affected — the limit only stops the
             next one. --}}
        <flux:callout icon="lock-closed">
            <flux:callout.heading>This product is at its shop limit</flux:callout.heading>
            <flux:callout.text>
                Your plan compares up to {{ $shopLimit }} {{ Str::plural('shop', $shopLimit ?? 0) }} per product, and this one has {{ $shopCount }}. All of them keep being checked. Only adding another one is blocked.
            </flux:callout.text>
            <flux:button class="mt-3" size="sm" :href="route('app.billing')" wire:navigate>
                Compare plans
            </flux:button>
        </flux:callout>
    @endif
</div>
