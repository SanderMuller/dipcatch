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
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-300 dark:ring-amber-400/20">
            These shops report different article numbers (EAN): {{ implode(', ', $mismatchedGtinHosts) }}. They may be different pack sizes or products.
        </p>
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
            <summary class="flex cursor-pointer list-none items-center justify-end gap-3 rounded-lg select-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 [&::-webkit-details-marker]:hidden">
                {{-- Stays visible while the form is open: that is exactly
                     when someone wants to know how much room is left. --}}
                @if ($shopLimit !== null)
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $shopCount }} of {{ $shopLimit }} shops</span>
                @endif

                {{-- Primary while closed, secondary once open: the panel it
                     reveals has its own primary action, and a page should
                     only offer one. --}}
                <span class="inline-flex items-center gap-1.5 rounded-lg py-2 pr-3 pl-2 text-sm font-semibold shadow-sm bg-primary-600 text-white hover:bg-primary-500 group-open:bg-white group-open:text-gray-700 group-open:ring-1 group-open:ring-gray-300 group-open:ring-inset group-open:hover:bg-gray-50 dark:group-open:bg-white/5 dark:group-open:text-gray-200 dark:group-open:ring-white/10 dark:group-open:hover:bg-white/10">
                    <svg viewBox="0 0 20 20" fill="currentColor" class="size-5 group-open:hidden" aria-hidden="true">
                        <path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" />
                    </svg>
                    <svg viewBox="0 0 20 20" fill="currentColor" class="hidden size-5 group-open:block" aria-hidden="true">
                        <path d="M5.22 5.22a.75.75 0 0 1 1.06 0L10 8.94l3.72-3.72a.75.75 0 1 1 1.06 1.06L11.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06L10 11.06l-3.72 3.72a.75.75 0 0 1-1.06-1.06L8.94 10 5.22 6.28a.75.75 0 0 1 0-1.06Z" />
                    </svg>
                    <span class="group-open:hidden">Add a shop</span>
                    <span class="hidden group-open:inline">Cancel</span>
                </span>
            </summary>

            <div class="mt-4 w-full rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                @livewire('shops.add-shop', ['product' => $product], key('add-shop-inline-' . $product->id))
            </div>
        </details>
    @else
        {{-- No Add button and no suggestions: every one of them would end at
             the same refusal on Confirm, after a live fetch of the page.
             Nothing already tracked is affected — the limit only stops the
             next one. --}}
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="flex items-start gap-3">
                <svg viewBox="0 0 20 20" fill="currentColor" class="mt-0.5 size-5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V8H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 7V5.5a3 3 0 1 0-6 0V8h6Z" clip-rule="evenodd" />
                </svg>
                <div class="text-sm">
                    <p class="font-medium text-gray-950 dark:text-white">
                        This product is at its shop limit
                    </p>
                    <p class="mt-1 text-gray-500 dark:text-gray-400">
                        Your plan compares up to {{ $shopLimit }} {{ Str::plural('shop', $shopLimit ?? 0) }} per product, and this one has {{ $shopCount }}. All of them keep being checked. Only adding another one is blocked.
                    </p>
                </div>
            </div>

            <a
                href="{{ url('/app/billing') }}"
                class="inline-flex shrink-0 items-center rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10"
            >Compare plans</a>
        </div>
    @endif
</div>
