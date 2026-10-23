{{-- Step 1's results for a typed name: the catalogue and bol.com first, the
     web below on request. A row is a button that sends its index, so the
     wizard probes the link it listed, never one the browser sends. --}}
@php
    $localCount = count($nameResults['items']);
    $webBlock = $this->webSearchBlock();
    $webNote = $webStatus?->note();
    $webFound = $webStatus === \App\Services\ProductSearch\WebNameSearchStatus::Found;
    $offersWeb = $this->offersWebSearch();
@endphp
{{-- Keyed on what the block shows, so a new search or web answer replaces
     it whole and Alpine reads the new searched text, rather than a morph
     keeping the bindings of the last one. --}}
<section class="space-y-3" aria-labelledby="name-results-heading" data-test="name-results" wire:key="name-results-{{ md5(json_encode([$searchedQuery, $webStatus, $webVerdict, $webCapSpent])) }}">
    <flux:heading level="3" id="name-results-heading" tabindex="-1" class="focus:outline-none">
        {{ __('Results for “:text”', ['text' => $searchedQuery]) }}
    </flux:heading>

    @if ($localCount === 0)
        <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="name-results-empty">
            {{ $offersWeb ? __('Nothing found in the supermarkets or at bol.com. Search the web, or paste the product’s link.') : __('Nothing found in the supermarkets or at bol.com. Paste the product’s link instead.') }}
        </flux:text>
    @else
        @include('livewire.products.partials.name-search-rows', ['rows' => $nameResults['items'], 'offset' => 0])
    @endif

    @if ($nameResults['bolFailed'])
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="name-results-bol-failed">{{ __('bol.com did not answer. Try again in a moment.') }}</flux:text>
    @endif

    @if ($webFound)
        <flux:heading level="3" id="web-results-heading" tabindex="-1" class="pt-2 focus:outline-none">{{ __('From the web') }}</flux:heading>
        @if ($webResults === [])
            <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="web-results-empty">{{ __('The web search found no shops for this name.') }}</flux:text>
        @else
            @include('livewire.products.partials.name-search-rows', ['rows' => $webResults, 'offset' => $localCount])
        @endif
    @elseif ($offersWeb)
        {{-- Off while the input holds other text than was searched, so the
             web search never runs for words the results do not describe. --}}
        <div class="flex flex-wrap items-center gap-3" x-data>
            <flux:button
                type="button"
                icon="globe-alt"
                wire:click="searchTheWeb"
                :disabled="$webBlock !== null || ! $this->searchedQueryIsShown()"
                x-bind:disabled="{{ $webBlock !== null ? 'true' : 'false' }} || ($wire.query ?? '').trim() !== {{ \Illuminate\Support\Js::from($searchedQuery) }}"
                data-test="search-the-web"
            >
                <span wire:loading.remove wire:target="searchTheWeb">{{ __('Search the web') }}</span>
                <span wire:loading wire:target="searchTheWeb">{{ __('Searching the web…') }}</span>
            </flux:button>
        </div>
        @if (($webBlock ?? $webNote) !== null)
            <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400" data-test="web-search-note">{{ $webBlock ?? $webNote }}</flux:text>
        @endif
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" @style(['display: none' => $this->searchedQueryIsShown()]) x-show="($wire.query ?? '').trim() !== {{ \Illuminate\Support\Js::from($searchedQuery) }}">
            {{ __('Look up the new text first, then search the web for it.') }}
        </flux:text>
    @endif

    {{-- A link gets the name, photo and price read from the page; the hand
         form gets none of that, so the link is the way forward. --}}
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="name-results-paste-link">
        {{ __('Not listed? Open the product at the shop you buy it from, copy the link from the address bar, and paste it in the field above.') }}
    </flux:text>
</section>
